<?php
namespace VladAero\Controllers;

use VladAero\Core\{Controller, Session, Database};

/**
 * PhotoController - Фотогалерея споттинга
 */
class PhotoController extends Controller
{
    public function index()
    {
        $prefix = $this->db->prefix();
        $perPage = $this->config['per_page']['default'] ?? 24;
        $page = max(1, (int)($this->query('page') ?? 1));
        $search = $this->query('q') ?: '';
        $sort = $this->query('sort') ?? 'newest';
        $aircraftId = (int)($this->query('aircraft_id') ?? 0);
        $airportId = (int)($this->query('airport_id') ?? 0);

        $where = ["p.status = 'approved'"];
        $params = [];

        if ($search) {
            $where[] = "(p.description LIKE :q OR p.title LIKE :q)";
            $params['q'] = "%{$search}%";
        }
        if ($aircraftId) {
            $where[] = "p.aircraft_id = :aid";
            $params['aid'] = $aircraftId;
        }
        if ($airportId) {
            $where[] = "p.airport_id = :apid";
            $params['apid'] = $airportId;
        }

        $orderBy = match ($sort) {
            'rating' => 'p.rating DESC, p.rating_count DESC',
            'popular' => 'p.views DESC',
            default => 'p.created_at DESC',
        };

        $whereStr = 'WHERE ' . implode(' AND ', $where);

        $total = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM {$prefix}photos p {$whereStr}", $params
        );
        $pagination = paginate($total, $perPage, $page);

        $photos = $this->db->fetchAll(
            "SELECT p.*, u.username,
                    ac.name as aircraft_name,
                    ap.name as airport_name, ap.icao_code as airport_icao
             FROM {$prefix}photos p
             LEFT JOIN {$prefix}users u ON p.user_id = u.id
             LEFT JOIN {$prefix}aircraft_types ac ON p.aircraft_id = ac.id
             LEFT JOIN {$prefix}airports ap ON p.airport_id = ap.id
             {$whereStr} ORDER BY {$orderBy}
             LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
            $params
        );

        $this->view('pages.photos.index', compact('photos', 'search', 'sort', 'pagination', 'total'));
    }

    public function show(string $id)
    {
        $prefix = $this->db->prefix();
        $id = (int)$id;

        $photo = $this->db->fetchOne(
            "SELECT p.*, u.username, u.id as user_id,
                    ac.name as aircraft_name, ac.type_code,
                    ap.name as airport_name, ap.icao_code as airport_icao,
                    al.name as airline_name
             FROM {$prefix}photos p
             LEFT JOIN {$prefix}users u ON p.user_id = u.id
             LEFT JOIN {$prefix}aircraft_types ac ON p.aircraft_id = ac.id
             LEFT JOIN {$prefix}airports ap ON p.airport_id = ap.id
             LEFT JOIN {$prefix}airlines al ON p.airline_id = al.id
             WHERE p.id = :id AND p.status = 'approved'",
            ['id' => $id]
        );

        if (!$photo) $this->abort(404, 'Фото не найдено');

        $this->db->query("UPDATE {$prefix}photos SET views = views + 1 WHERE id = :id", ['id' => $id]);

        $comments = $this->db->fetchAll(
            "SELECT c.*, u.username FROM {$prefix}comments c
             LEFT JOIN {$prefix}users u ON c.user_id = u.id
             WHERE c.photo_id = :id ORDER BY c.created_at ASC",
            ['id' => $id]
        );

        $related = $this->db->fetchAll(
            "SELECT id, title, file_path, views_count, likes_count, rating
             FROM {$prefix}photos
             WHERE id != :id AND status = 'approved'
             AND (aircraft_id = :aid OR airport_id = :apid)
             ORDER BY id DESC LIMIT 6",
            ['id' => $id, 'aid' => $photo['aircraft_id'] ?? 0, 'apid' => $photo['airport_id'] ?? 0]
        );

        $this->view('pages.photos.show', compact('photo', 'comments', 'related'));
    }

    public function upload()
    {
        $user = $this->requireAuth();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->view('pages.photos.upload', ['title' => 'Загрузить фото']);
            return;
        }

        $this->verifyCsrf();
        $prefix = $this->db->prefix();

        if (empty($_FILES['photo']['tmp_name']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            Session::setFlash('error', 'Ошибка загрузки файла');
            $this->redirect('/photos/upload');
        }

        $file = $_FILES['photo'];
        $maxSize = $this->config['upload']['max_photo_size'] ?? 20 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            Session::setFlash('error', 'Файл слишком большой (макс. 20 МБ)');
            $this->redirect('/photos/upload');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        $allowed = $this->config['upload']['allowed_photo_types'] ?? ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($mime, $allowed)) {
            Session::setFlash('error', 'Недопустимый формат файла');
            $this->redirect('/photos/upload');
        }

        $uploadDir = PROJECT_ROOT . '/public/uploads/photos/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $ext = match($mime) { 'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg' };
        $filename = 'photo_' . $user['id'] . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $filepath = $uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            Session::setFlash('error', 'Ошибка сохранения файла');
            $this->redirect('/photos/upload');
        }

        // Create thumbnail
        $this->createThumbnail($filepath, $uploadDir . 'thumbnails/', $filename);

        $photoId = $this->db->insert('photos', [
            'user_id' => $user['id'],
            'title' => $this->input('title') ?? '',
            'description' => $_POST['description'] ?? '',
            'file_path' => 'uploads/photos/' . $filename,
            'file_size' => $file['size'],
            'width' => imagesx(@imagecreatefromstring(file_get_contents($filepath))) ?: 0,
            'height' => imagesy(@imagecreatefromstring(file_get_contents($filepath))) ?: 0,
            'shot_date' => $this->input('shot_date') ?: date('Y-m-d'),
            'status' => 'pending',
        ]);

        Session::setFlash('success', 'Фото загружено и ожидает модерации');
        $this->redirect('/photos/' . $photoId);
    }

    private function createThumbnail(string $src, string $thumbDir, string $filename): void
    {
        if (!is_dir($thumbDir)) mkdir($thumbDir, 0755, true);

        $thumbW = $this->config['upload']['thumbnail_width'] ?? 400;
        $thumbH = $this->config['upload']['thumbnail_height'] ?? 300;

        list($origW, $origH) = getimagesize($src);
        $srcImg = match(pathinfo($src, PATHINFO_EXTENSION)) {
            'png' => imagecreatefrompng($src),
            'webp' => imagecreatefromwebp($src),
            default => imagecreatefromjpeg($src),
        };
        if (!$srcImg) return;

        $thumb = imagecreatetruecolor($thumbW, $thumbH);
        imagecopyresampled($thumb, $srcImg, 0, 0, 0, 0, $thumbW, $thumbH, $origW, $origH);

        $thumbPath = $thumbDir . $filename;
        match(pathinfo($src, PATHINFO_EXTENSION)) {
            'png' => imagepng($thumb, $thumbPath, 6),
            'webp' => imagewebp($thumb, $thumbPath, 85),
            default => imagejpeg($thumb, $thumbPath, 85),
        };

        imagedestroy($srcImg);
        imagedestroy($thumb);
    }

    public function rate(string $id)
    {
        $user = $this->requireAuth();

        $id = (int)$id;
        $rating = max(1, min(5, (int)($this->input('rating') ?? 3)));
        $prefix = $this->db->prefix();

        $this->db->query(
            "INSERT INTO {$prefix}photo_ratings (photo_id, user_id, rating) VALUES (:pid, :uid, :r)
             ON DUPLICATE KEY UPDATE rating = :r2",
            ['pid' => $id, 'uid' => $user['id'], 'r' => $rating, 'r2' => $rating]
        );

        $avg = $this->db->fetchColumn(
            "SELECT AVG(rating) FROM {$prefix}photo_ratings WHERE photo_id = :pid", ['pid' => $id]
        );
        $count = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {$prefix}photo_ratings WHERE photo_id = :pid", ['pid' => $id]
        );

        $this->db->query(
            "UPDATE {$prefix}photos SET rating = :r, rating_count = :rc WHERE id = :id",
            ['r' => round($avg, 2), 'rc' => $count, 'id' => $id]
        );

        $this->json(['rating' => round($avg, 2), 'count' => $count]);
    }
}
