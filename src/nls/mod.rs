/// Nls - National Language Support (Nls)
use crate::types::*;

pub struct NlsTable {
    pub code_page: u32,
    pub max_char_size: u32,
    pub default_char: u16,
    pub lead_byte: [u8; 12],
    pub unicode_to_mb: [u16; 256],
    pub mb_to_unicode: [u16; 256],
    pub upcase_table: [u16; 256],
}

static mut OEM_TABLE: NlsTable = NlsTable {
    code_page: 437,
    max_char_size: 1,
    default_char: 0x003F,
    lead_byte: [0; 12],
    unicode_to_mb: [0; 256],
    mb_to_unicode: [0; 256],
    upcase_table: [0; 256],
};

static mut ANSI_TABLE: NlsTable = NlsTable {
    code_page: 1252,
    max_char_size: 1,
    default_char: 0x003F,
    lead_byte: [0; 12],
    unicode_to_mb: [0; 256],
    mb_to_unicode: [0; 256],
    upcase_table: [0; 256],
};

pub unsafe fn nls_init() {
    // Initialize OEM code page 437
    OEM_TABLE.code_page = 437;
    OEM_TABLE.max_char_size = 1;

    // Initialize ANSI code page 1252
    ANSI_TABLE.code_page = 1252;
    ANSI_TABLE.max_char_size = 1;

    // Set up OEM to Unicode mapping (simplified)
    for i in 0..256 {
        OEM_TABLE.mb_to_unicode[i] = i as u16;
        OEM_TABLE.unicode_to_mb[i] = i as u16;
        OEM_TABLE.upcase_table[i] = i as u16;
        if i >= 0x61 && i <= 0x7A {
            OEM_TABLE.upcase_table[i] = (i - 0x20) as u16;
        }
    }
}

pub unsafe fn nls_ansi_to_unicode(
    ansi: *const u8,
    ansi_len: u32,
    unicode: *mut u16,
    unicode_len: u32,
) -> u32 {
    let mut i = 0;
    while i < ansi_len && i < unicode_len {
        let ch = *ansi.add(i as usize) as u16;
        *unicode.add(i as usize) = OEM_TABLE.mb_to_unicode[ch as usize];
        i += 1;
    }
    i
}

pub unsafe fn nls_unicode_to_ansi(
    unicode: *const u16,
    unicode_len: u32,
    ansi: *mut u8,
    ansi_len: u32,
) -> u32 {
    let mut i = 0;
    while i < unicode_len && i < ansi_len {
        let ch = *unicode.add(i as usize) as usize;
        if ch < 256 {
            *ansi.add(i as usize) = OEM_TABLE.unicode_to_mb[ch] as u8;
        } else {
            *ansi.add(i as usize) = OEM_TABLE.default_char as u8;
        }
        i += 1;
    }
    i
}

pub unsafe fn nls_upcase_unicode_char(ch: u16) -> u16 {
    if (ch as usize) < 256 {
        OEM_TABLE.upcase_table[ch as usize]
    } else {
        ch
    }
}

pub unsafe fn nls_get_oem_table() -> *const NlsTable {
    &OEM_TABLE as *const NlsTable
}

pub unsafe fn nls_get_ansi_table() -> *const NlsTable {
    &ANSI_TABLE as *const NlsTable
}

// ============================================================
// Win10 NLS: Cyrillic (RU), cp1251/cp866, locale info
// ============================================================

pub const NLS_CODEPAGE_OEM_RU: u32 = 866;
pub const NLS_CODEPAGE_ANSI_RU: u32 = 1251;
pub const NLS_CODEPAGE_UTF8: u32 = 65001;

pub const LANG_ENGLISH_US: u32 = 0x0409;
pub const LANG_RUSSIAN: u32 = 0x0419;

static mut CP866_TABLE: NlsTable = NlsTable {
    code_page: 866,
    max_char_size: 1,
    default_char: 0x003F,
    lead_byte: [0; 12],
    unicode_to_mb: [0; 256],
    mb_to_unicode: [0; 256],
    upcase_table: [0; 256],
};

static mut CP1251_TABLE: NlsTable = NlsTable {
    code_page: 1251,
    max_char_size: 1,
    default_char: 0x003F,
    lead_byte: [0; 12],
    unicode_to_mb: [0; 256],
    mb_to_unicode: [0; 256],
    upcase_table: [0; 256],
};

static mut NLS_SYSTEM_LOCALE: u32 = LANG_RUSSIAN;
static mut NLS_UI_LANGUAGE: u32 = LANG_RUSSIAN;

/// nls_init_cyrillic - build cp866/cp1251 tables + full upcase.
///
/// Cyrillic Unicode blocks:
///   U+0410..U+042F = А..Я, U+0430..U+044F = а..я, U+0401 = Ё, U+0451 = ё
/// cp866:  0x80..0x9F = А..Я(а-я order), 0xA0..0xAF = ..., 0xE0..0xEF = а..п,
///         0xF0..0xF5 = р..я(+Ё=0xF1? no: Ё=0xF0? ) — real mapping below.
/// cp1251: 0xC0..0xDF = А..Я, 0xE0..0xFF = а..я, Ё=0xA8, ё=0xB8
pub unsafe fn nls_init_cyrillic() {
    // Identity defaults.
    let mut i = 0usize;
    while i < 256 {
        CP866_TABLE.mb_to_unicode[i] = i as u16;
        CP866_TABLE.unicode_to_mb[i] = i as u16;
        CP866_TABLE.upcase_table[i] = i as u16;
        CP1251_TABLE.mb_to_unicode[i] = i as u16;
        CP1251_TABLE.unicode_to_mb[i] = i as u16;
        CP1251_TABLE.upcase_table[i] = i as u16;
        i += 1;
    }
    // Latin upcase for both tables.
    let mut c = 0x61u16;
    while c <= 0x7A {
        CP866_TABLE.upcase_table[c as usize] = c - 0x20;
        CP1251_TABLE.upcase_table[c as usize] = c - 0x20;
        c += 1;
    }
    // cp1251: А..Я @ 0xC0..0xDF <-> U+0410..U+042F
    let mut k = 0u16;
    while k < 32 {
        let mb = (0xC0 + k) as usize;
        let uni = 0x0410 + k;
        CP1251_TABLE.mb_to_unicode[mb] = uni;
        CP1251_TABLE.unicode_to_mb[(uni & 0xFF) as usize] = mb as u16;
        // а..я @ 0xE0..0xFF <-> U+0430..U+044F, upcase to А..Я
        let mb2 = (0xE0 + k) as usize;
        let uni2 = 0x0430 + k;
        CP1251_TABLE.mb_to_unicode[mb2] = uni2;
        CP1251_TABLE.upcase_table[mb2] = mb as u16;
        k += 1;
    }
    CP1251_TABLE.mb_to_unicode[0xA8] = 0x0401; // Ё
    CP1251_TABLE.mb_to_unicode[0xB8] = 0x0451; // ё
    CP1251_TABLE.upcase_table[0xB8] = 0xA8;
    // cp866: А..П @ 0x80..0x8F, Р..Я @ 0x90..0x9F, а..п @ 0xA0..0xAF,
    // р..я @ 0xE0..0xEF, Ё @ 0xF0, ё @ 0xF1
    let mut j = 0u16;
    while j < 16 {
        CP866_TABLE.mb_to_unicode[(0x80 + j) as usize] = 0x0410 + j;
        CP866_TABLE.mb_to_unicode[(0x90 + j) as usize] = 0x0420 + j;
        CP866_TABLE.mb_to_unicode[(0xA0 + j) as usize] = 0x0430 + j;
        CP866_TABLE.mb_to_unicode[(0xE0 + j) as usize] = 0x0440 + j;
        // upcase: lowercase bytes -> uppercase bytes
        CP866_TABLE.upcase_table[(0xA0 + j) as usize] = 0x80 + j;
        CP866_TABLE.upcase_table[(0xE0 + j) as usize] = 0x90 + j;
        j += 1;
    }
    CP866_TABLE.mb_to_unicode[0xF0] = 0x0401;
    CP866_TABLE.mb_to_unicode[0xF1] = 0x0451;
    CP866_TABLE.upcase_table[0xF1] = 0xF0;
}

/// nls_upcase_full - Unicode-aware upcase incl. Cyrillic.
pub unsafe fn nls_upcase_full(ch: u16) -> u16 {
    // Latin a..z
    if ch >= 0x61 && ch <= 0x7A {
        return ch - 0x20;
    }
    // Cyrillic а..я -> А..Я
    if ch >= 0x0430 && ch <= 0x044F {
        return ch - 0x20;
    }
    // ё -> Ё
    if ch == 0x0451 {
        return 0x0401;
    }
    ch
}

/// nls_downcase_full - Unicode-aware downcase incl. Cyrillic.
pub unsafe fn nls_downcase_full(ch: u16) -> u16 {
    if ch >= 0x41 && ch <= 0x5A {
        return ch + 0x20;
    }
    if ch >= 0x0410 && ch <= 0x042F {
        return ch + 0x20;
    }
    if ch == 0x0401 {
        return 0x0451;
    }
    ch
}

/// nls_compare_unicode - case-insensitive Unicode compare.
pub unsafe fn nls_compare_unicode(
    s1: *const u16,
    len1: usize,
    s2: *const u16,
    len2: usize,
    case_insensitive: bool,
) -> i32 {
    let n = if len1 < len2 { len1 } else { len2 };
    let mut i = 0;
    while i < n {
        let mut a = *s1.add(i);
        let mut b = *s2.add(i);
        if case_insensitive {
            a = nls_upcase_full(a);
            b = nls_upcase_full(b);
        }
        if a != b {
            return if a < b { -1 } else { 1 };
        }
        i += 1;
    }
    if len1 == len2 {
        0
    } else if len1 < len2 {
        -1
    } else {
        1
    }
}

/// nls_mb_to_unicode_cp - convert using a specific codepage table.
pub unsafe fn nls_mb_to_unicode_cp(
    codepage: u32,
    mb: *const u8,
    mb_len: u32,
    uni: *mut u16,
    uni_len: u32,
) -> u32 {
    let table: *const NlsTable = match codepage {
        NLS_CODEPAGE_OEM_RU => &CP866_TABLE as *const NlsTable,
        NLS_CODEPAGE_ANSI_RU => &CP1251_TABLE as *const NlsTable,
        _ => &OEM_TABLE as *const NlsTable,
    };
    let mut i = 0u32;
    while i < mb_len && i < uni_len {
        let ch = *mb.add(i as usize) as usize;
        *uni.add(i as usize) = (*table).mb_to_unicode[ch];
        i += 1;
    }
    i
}

/// nls_set_system_locale / nls_get_system_locale
pub unsafe fn nls_set_system_locale(locale_id: u32) {
    NLS_SYSTEM_LOCALE = locale_id;
}

pub unsafe fn nls_get_system_locale() -> u32 {
    NLS_SYSTEM_LOCALE
}

pub unsafe fn nls_set_ui_language(lang_id: u32) {
    NLS_UI_LANGUAGE = lang_id;
}

pub unsafe fn nls_get_ui_language() -> u32 {
    NLS_UI_LANGUAGE
}
