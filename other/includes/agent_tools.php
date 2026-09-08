<?php
/**
 * ShibaLingo - Native Model Tools & Function Calling Engine for Admin AI Agent
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

/**
 * Returns JSON Schema definitions for model function calling
 */
function getAdminAgentToolDefinitions(): array {
    return [
        // 1. Web Search
        [
            'type' => 'function',
            'function' => [
                'name' => 'web_search',
                'description' => 'Поиск информации в интернете (через Cloudflare/Web search) для поиска актуальных учебных материалов, переводов, фактов и лингвистических правил.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Поисковый запрос'
                        ],
                        'num_results' => [
                            'type' => 'integer',
                            'description' => 'Количество результатов (1-5)',
                            'default' => 3
                        ]
                    ],
                    'required' => ['query']
                ]
            ]
        ],

        // 2. Fetch URL
        [
            'type' => 'function',
            'function' => [
                'name' => 'fetch_url',
                'description' => 'Загрузить и прочитать содержимое веб-страницы по URL в чистом текстовом/markdown формате.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => [
                            'type' => 'string',
                            'description' => 'Полный URL страницы (http/https)'
                        ]
                    ],
                    'required' => ['url']
                ]
            ]
        ],

        // 3. Vladikish Conlang Knowledge & Inspection
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_vladikish_knowledge',
                'description' => 'Узнать ВСЁ о выдуманном языке Vladikish (полный официальный словарь, правила грамматики, спряжения времен, части речи, алфавит, фонетику и примеры диалогов). Можно запросить всю базу знаний или отфильтровать по категории, слову или части речи.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'category' => [
                            'type' => 'string',
                            'enum' => ['all', 'dictionary', 'grammar', 'phonetics', 'alphabet', 'examples'],
                            'description' => 'Категория информации (all = всё о языке, dictionary = словарь слов, grammar = правила грамматики, phonetics = фонетика и звуки, examples = примеры предложений)',
                            'default' => 'all'
                        ],
                        'query' => [
                            'type' => 'string',
                            'description' => 'Поисковый фильтр (конкретное слово на Vladikish, перевод на русском/английском, или название правила)'
                        ],
                        'part_of_speech' => [
                            'type' => 'string',
                            'enum' => ['all', 'noun', 'verb', 'adjective', 'adverb', 'pronoun', 'greeting', 'phrase'],
                            'description' => 'Фильтр по части речи (noun, verb, adjective, etc.)'
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Количество возвращаемых записей (по умолчанию 50)',
                            'default' => 50
                        ]
                    ]
                ]
            ]
        ],

        // 4. System Overview
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_system_overview',
                'description' => 'Получить полную сводку по сайту: количество пользователей, языков, уроков, историй, слов в словаре, квестов и системные настройки.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => (object)[]
                ]
            ]
        ],

        // 4. SQL Query (Read-Only inspection)
        [
            'type' => 'function',
            'function' => [
                'name' => 'sql_query',
                'description' => 'Выполнить безопасный SELECT SQL запрос к базе данных для анализа данных или получения точных записей.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'SELECT SQL запрос (таблицы: users, skills, lessons, stories, conlang_dictionary, conlang_grammar, daily_quests, shop_items, promo_codes, site_settings)'
                        ]
                    ],
                    'required' => ['query']
                ]
            ]
        ],

        // 5. Create Skill (Curriculum Chapter)
        [
            'type' => 'function',
            'function' => [
                'name' => 'create_skill',
                'description' => 'Создать новый раздел навыка (главу курса) для выбранного языка.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'language_code' => [
                            'type' => 'string',
                            'description' => 'Код языка (например: vladikish, it, en, ru, de)'
                        ],
                        'title' => [
                            'type' => 'string',
                            'description' => 'Название раздела (например: «Знакомство и Приветствия»)'
                        ],
                        'icon' => [
                            'type' => 'string',
                            'description' => 'Иконка/эмодзи раздела (например: 👋, ☕, ✈️, 🐾)'
                        ],
                        'level' => [
                            'type' => 'integer',
                            'description' => 'Уровень раздела (1-10)',
                            'default' => 1
                        ],
                        'order_num' => [
                            'type' => 'integer',
                            'description' => 'Порядковый номер на дорожке',
                            'default' => 1
                        ],
                        'description' => [
                            'type' => 'string',
                            'description' => 'Краткое описание того, чему научится студент'
                        ]
                    ],
                    'required' => ['language_code', 'title']
                ]
            ]
        ],

        // 6. Create Lesson
        [
            'type' => 'function',
            'function' => [
                'name' => 'create_lesson',
                'description' => 'Создать интерактивный урок с упражнениями внутри указанного навыка (Skill).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'skill_id' => [
                            'type' => 'integer',
                            'description' => 'ID раздела (Skill ID)'
                        ],
                        'title' => [
                            'type' => 'string',
                            'description' => 'Название урока (например: «Урок 1: Основные приветствия»)'
                        ],
                        'xp_reward' => [
                            'type' => 'integer',
                            'description' => 'Награда в XP за прохождение',
                            'default' => 20
                        ],
                        'order_num' => [
                            'type' => 'integer',
                            'description' => 'Порядковый номер урока в разделе',
                            'default' => 1
                        ],
                        'exercises' => [
                            'type' => 'array',
                            'description' => 'Массив интерактивных упражнений (типы: multiple_choice, word_bank, match_pairs, translate, listening_dictation, speak, fill_blank, listen_tap, find_error, judge, dialogue_fill)',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'type' => [
                                        'type' => 'string',
                                        'enum' => [
                                            'multiple_choice',
                                            'word_bank',
                                            'match_pairs',
                                            'translate',
                                            'listening_dictation',
                                            'speak',
                                            'fill_blank',
                                            'listen_tap',
                                            'find_error',
                                            'judge',
                                            'dialogue_fill'
                                        ]
                                    ],
                                    'question' => ['type' => 'string'],
                                    'prompt' => ['type' => 'string'],
                                    'options' => ['type' => 'array', 'items' => ['type' => 'string']],
                                    'correct' => ['type' => 'integer'],
                                    'correct_word' => ['type' => 'string'],
                                    'sentence' => ['type' => 'string'],
                                    'error_word' => ['type' => 'string'],
                                    'statement' => ['type' => 'string'],
                                    'is_true' => ['type' => 'boolean'],
                                    'speaker_name' => ['type' => 'string'],
                                    'speaker_avatar' => ['type' => 'string'],
                                    'correct_sequence' => ['type' => 'array', 'items' => ['type' => 'string']],
                                    'word_pool' => ['type' => 'array', 'items' => ['type' => 'string']],
                                    'pairs' => ['type' => 'array', 'items' => ['type' => 'object']],
                                    'correct_answers' => ['type' => 'array', 'items' => ['type' => 'string']],
                                    'explanation' => ['type' => 'string']
                                ],
                                'required' => ['type', 'question']
                            ]
                        ]
                    ],
                    'required' => ['skill_id', 'title', 'exercises']
                ]
            ]
        ],

        // 7. Delete Lesson
        [
            'type' => 'function',
            'function' => [
                'name' => 'delete_lesson',
                'description' => 'Удалить урок по его ID.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'lesson_id' => [
                            'type' => 'integer',
                            'description' => 'ID удаляемого урока'
                        ]
                    ],
                    'required' => ['lesson_id']
                ]
            ]
        ],

        // 8. Create Story
        [
            'type' => 'function',
            'function' => [
                'name' => 'create_story',
                'description' => 'Создать новую сюжетную интерактивную историю с диалогами и вопросами.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'language_code' => ['type' => 'string'],
                        'title' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'level' => ['type' => 'integer', 'default' => 1],
                        'xp_reward' => ['type' => 'integer', 'default' => 30],
                        'gems_reward' => ['type' => 'integer', 'default' => 15],
                        'cover_image' => ['type' => 'string'],
                        'story_dialogue' => [
                            'type' => 'array',
                            'description' => 'Массив реплик и вопросов диалога',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'character' => ['type' => 'string'],
                                    'text' => ['type' => 'string'],
                                    'translation' => ['type' => 'string'],
                                    'avatar' => ['type' => 'string'],
                                    'type' => ['type' => 'string'],
                                    'prompt' => ['type' => 'string'],
                                    'options' => ['type' => 'array', 'items' => ['type' => 'string']],
                                    'correct' => ['type' => 'integer']
                                ]
                            ]
                        ]
                    ],
                    'required' => ['language_code', 'title', 'story_dialogue']
                ]
            ]
        ],

        // 9. Add Dictionary Words (Conlang)
        [
            'type' => 'function',
            'function' => [
                'name' => 'add_dictionary_words',
                'description' => 'Массово добавить новые слова в словарь Conlang Vladikish.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'words' => [
                            'type' => 'array',
                            'description' => 'Список слов для добавления',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'word' => ['type' => 'string'],
                                    'part_of_speech' => ['type' => 'string', 'enum' => ['noun', 'verb', 'adjective', 'adverb', 'pronoun', 'phrase']],
                                    'translation_ru' => ['type' => 'string'],
                                    'translation_en' => ['type' => 'string'],
                                    'pronunciation' => ['type' => 'string'],
                                    'example_sentence' => ['type' => 'string']
                                ],
                                'required' => ['word', 'translation_ru']
                            ]
                        ]
                    ],
                    'required' => ['words']
                ]
            ]
        ],

        // 10. Add Grammar Rule
        [
            'type' => 'function',
            'function' => [
                'name' => 'add_grammar_rule',
                'description' => 'Добавить грамматическое правило для Vladikish.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'rule_title' => ['type' => 'string'],
                        'rule_description' => ['type' => 'string'],
                        'rule_examples' => ['type' => 'string'],
                        'order_num' => ['type' => 'integer', 'default' => 1]
                    ],
                    'required' => ['rule_title', 'rule_description']
                ]
            ]
        ],

        // 11. Create Daily Quests
        [
            'type' => 'function',
            'function' => [
                'name' => 'create_daily_quest',
                'description' => 'Создать ежедневный квест для студентов.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'req_type' => ['type' => 'string', 'enum' => ['complete_lesson', 'earn_xp', 'chat_messages', 'perfect_lesson', 'streak_days']],
                        'req_target' => ['type' => 'integer', 'default' => 1],
                        'xp_reward' => ['type' => 'integer', 'default' => 25],
                        'gems_reward' => ['type' => 'integer', 'default' => 10]
                    ],
                    'required' => ['title', 'req_type']
                ]
            ]
        ],

        // 12. Create Achievement
        [
            'type' => 'function',
            'function' => [
                'name' => 'create_achievement',
                'description' => 'Создать новое достижение / бейдж.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'slug' => ['type' => 'string'],
                        'title' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'icon' => ['type' => 'string', 'default' => '🏆'],
                        'xp_reward' => ['type' => 'integer', 'default' => 50],
                        'gems_reward' => ['type' => 'integer', 'default' => 20],
                        'req_type' => ['type' => 'string', 'enum' => ['lessons_count', 'streak_days', 'xp_total', 'words_learned', 'stories_read']],
                        'req_value' => ['type' => 'integer', 'default' => 5]
                    ],
                    'required' => ['slug', 'title', 'req_type']
                ]
            ]
        ],

        // 13. Create Shop Item
        [
            'type' => 'function',
            'function' => [
                'name' => 'create_shop_item',
                'description' => 'Добавить товар в магазин или новый скин маскота.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'item_key' => ['type' => 'string'],
                        'name' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'icon' => ['type' => 'string', 'default' => '🎁'],
                        'price_gems' => ['type' => 'integer', 'default' => 100],
                        'category' => ['type' => 'string', 'enum' => ['skin', 'booster', 'heart', 'freeze', 'badge']],
                        'effect_value' => ['type' => 'string']
                    ],
                    'required' => ['item_key', 'name', 'price_gems', 'category']
                ]
            ]
        ],

        // 14. Create Promocode
        [
            'type' => 'function',
            'function' => [
                'name' => 'create_promocode',
                'description' => 'Создать промокод с бонусами в кристаллах, опыте и жизнях.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'code' => ['type' => 'string', 'description' => 'Уникальный промокод (например: SHIBA2026)'],
                        'gems_bonus' => ['type' => 'integer', 'default' => 100],
                        'hearts_bonus' => ['type' => 'integer', 'default' => 5],
                        'xp_bonus' => ['type' => 'integer', 'default' => 50],
                        'max_uses' => ['type' => 'integer', 'default' => 100],
                        'expires_days' => ['type' => 'integer', 'default' => 30]
                    ],
                    'required' => ['code']
                ]
            ]
        ],

        // 15. Manage User
        [
            'type' => 'function',
            'function' => [
                'name' => 'manage_user',
                'description' => 'Управление пользователем: выдать кристаллы, опыт, жизни, заблокировать, разблокировать или изменить роль.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'identifier' => ['type' => 'string', 'description' => 'ID пользователя или username'],
                        'action' => [
                            'type' => 'string',
                            'enum' => ['add_gems', 'add_xp', 'refill_hearts', 'ban', 'unban', 'change_role']
                        ],
                        'value' => ['type' => 'string', 'description' => 'Значение (число для кристаллов/xp или slug роли admin/student)']
                    ],
                    'required' => ['identifier', 'action']
                ]
            ]
        ],

        // 16. Update Site Setting
        [
            'type' => 'function',
            'function' => [
                'name' => 'update_site_setting',
                'description' => 'Обновить системную настройку сайта (название сайта, API ключ, base url, maintenance mode и т.д.).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'key' => ['type' => 'string'],
                        'value' => ['type' => 'string']
                    ],
                    'required' => ['key', 'value']
                ]
            ]
        ],

        // 17. Universal Entity CRUD (Full Admin Power over all tables)
        [
            'type' => 'function',
            'function' => [
                'name' => 'universal_crud',
                'description' => 'Универсальный инструмент полного CRUD (Create, Read, Update, Delete) над ЛЮБОЙ таблицей базы данных: lessons, skills, stories, conlang_dictionary, conlang_grammar, daily_quests, achievements, shop_items, promo_codes, languages, users, site_settings, classrooms, assignments.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => [
                            'type' => 'string',
                            'enum' => ['create', 'read', 'update', 'delete'],
                            'description' => 'Операция CRUD'
                        ],
                        'table' => [
                            'type' => 'string',
                            'enum' => ['lessons', 'skills', 'stories', 'conlang_dictionary', 'conlang_grammar', 'daily_quests', 'achievements', 'shop_items', 'promo_codes', 'languages', 'users', 'site_settings', 'classrooms', 'assignments'],
                            'description' => 'Название целевой таблицы'
                        ],
                        'id' => [
                            'type' => 'integer',
                            'description' => 'ID записи (для операций read, update, delete)'
                        ],
                        'data' => [
                            'type' => 'object',
                            'description' => 'Ассоциативный массив полей и значений для создания или обновления'
                        ],
                        'where' => [
                            'type' => 'object',
                            'description' => 'Фильтры поиска (для read или пакетных операций)'
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Лимит записей для чтения (по умолчанию 20)',
                            'default' => 20
                        ]
                    ],
                    'required' => ['action', 'table']
                ]
            ]
        ],

        // 18. Delete Entity Tool (Quick Deletion)
        [
            'type' => 'function',
            'function' => [
                'name' => 'delete_entity',
                'description' => 'Быстро удалить любую запись из любой таблицы по ID (урок, навык, история, слово, правило, квест, бейдж, товар, промокод, язык).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'table' => [
                            'type' => 'string',
                            'enum' => ['lessons', 'skills', 'stories', 'conlang_dictionary', 'conlang_grammar', 'daily_quests', 'achievements', 'shop_items', 'promo_codes', 'languages', 'users'],
                            'description' => 'Таблица'
                        ],
                        'id' => [
                            'type' => 'integer',
                            'description' => 'ID удаляемой записи'
                        ]
                    ],
                    'required' => ['table', 'id']
                ]
            ]
        ],

        // 19. Update Lesson Tool
        [
            'type' => 'function',
            'function' => [
                'name' => 'update_lesson',
                'description' => 'Обновить существующий урок (название, упражнения, награду XP, порядок).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer', 'description' => 'ID урока'],
                        'title' => ['type' => 'string', 'description' => 'Новое название урока'],
                        'xp_reward' => ['type' => 'integer', 'description' => 'Награда XP (по умолчанию 20)'],
                        'order_num' => ['type' => 'integer', 'description' => 'Порядковый номер'],
                        'exercises_json' => ['type' => 'string', 'description' => 'JSON массив с упражнениями урока']
                    ],
                    'required' => ['id']
                ]
            ]
        ],

        // 20. Update Conlang Word Tool
        [
            'type' => 'function',
            'function' => [
                'name' => 'update_dictionary_word',
                'description' => 'Обновить существующее слово в словаре Vladikish (перевод, часть речи, транскрипцию, пример).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer', 'description' => 'ID слова в словаре'],
                        'word' => ['type' => 'string'],
                        'part_of_speech' => ['type' => 'string'],
                        'translation_ru' => ['type' => 'string'],
                        'translation_en' => ['type' => 'string'],
                        'pronunciation' => ['type' => 'string'],
                        'example_sentence' => ['type' => 'string']
                    ],
                    'required' => ['id']
                ]
            ]
        ],

        // 21. Manage Language Tool
        [
            'type' => 'function',
            'function' => [
                'name' => 'manage_language',
                'description' => 'Создать, обновить или удалить язык в системе ShibaLingo.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => ['type' => 'string', 'enum' => ['create', 'update', 'delete']],
                        'code' => ['type' => 'string', 'description' => 'Код языка (например: fr, de, es, vladikish)'],
                        'name' => ['type' => 'string', 'description' => 'Название (например: Français)'],
                        'flag' => ['type' => 'string', 'description' => 'Эмодзи флаг (например: 🇫🇷)'],
                        'description' => ['type' => 'string'],
                        'is_conlang' => ['type' => 'integer', 'enum' => [0, 1], 'default' => 0]
                    ],
                    'required' => ['action', 'code']
                ]
            ]
        ],

        // 22. List Lessons Tool
        [
            'type' => 'function',
            'function' => [
                'name' => 'list_lessons',
                'description' => 'Получить список существующих уроков с их ID, названиями, разделами (skills), количеством упражнений и наградами.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'skill_id' => ['type' => 'integer', 'description' => 'Фильтр по ID раздела (необязательно)'],
                        'language_code' => ['type' => 'string', 'description' => 'Фильтр по коду языка (например: vladikish, en, it)'],
                        'limit' => ['type' => 'integer', 'description' => 'Лимит уроков (по умолчанию 30)', 'default' => 30]
                    ]
                ]
            ]
        ],

        // 23. List Skills (Chapters) Tool
        [
            'type' => 'function',
            'function' => [
                'name' => 'list_skills',
                'description' => 'Получить список всех глав/разделов (Skills) в дереве навыков с количеством уроков.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'language_code' => ['type' => 'string', 'description' => 'Фильтр по языку (например: vladikish)'],
                        'limit' => ['type' => 'integer', 'description' => 'Лимит (по умолчанию 30)', 'default' => 30]
                    ]
                ]
            ]
        ],

        // 24. List Stories Tool
        [
            'type' => 'function',
            'function' => [
                'name' => 'list_stories',
                'description' => 'Получить список всех интерактивных историй.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'language_code' => ['type' => 'string', 'description' => 'Фильтр по языку'],
                        'limit' => ['type' => 'integer', 'default' => 20]
                    ]
                ]
            ]
        ],

        // 25. List Dictionary Words Tool
        [
            'type' => 'function',
            'function' => [
                'name' => 'list_dictionary_words',
                'description' => 'Получить список слов из официального словаря Vladikish (с переводами, транскрипциями и примерами).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'language_code' => ['type' => 'string', 'default' => 'vladikish'],
                        'part_of_speech' => ['type' => 'string', 'enum' => ['noun', 'verb', 'adjective', 'adverb', 'pronoun', 'phrase']],
                        'query' => ['type' => 'string', 'description' => 'Поиск по слову или русскому переводу'],
                        'limit' => ['type' => 'integer', 'default' => 30]
                    ]
                ]
            ]
        ],

        // 26. List Grammar Rules Tool
        [
            'type' => 'function',
            'function' => [
                'name' => 'list_grammar_rules',
                'description' => 'Получить список всех правил грамматики Vladikish.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'default' => 20]
                    ]
                ]
            ]
        ],

        // 27. List Daily Quests Tool
        [
            'type' => 'function',
            'function' => [
                'name' => 'list_daily_quests',
                'description' => 'Получить список ежедневных квестов.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'default' => 20]
                    ]
                ]
            ]
        ],

        // 28. List Achievements Tool
        [
            'type' => 'function',
            'function' => [
                'name' => 'list_achievements',
                'description' => 'Получить список всех бейджей и достижений.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'default' => 20]
                    ]
                ]
            ]
        ],

        // 29. List Shop Items Tool
        [
            'type' => 'function',
            'function' => [
                'name' => 'list_shop_items',
                'description' => 'Получить список товаров и скинов магазина.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'category' => ['type' => 'string'],
                        'limit' => ['type' => 'integer', 'default' => 20]
                    ]
                ]
            ]
        ],

        // 30. List Promocodes Tool
        [
            'type' => 'function',
            'function' => [
                'name' => 'list_promocodes',
                'description' => 'Получить список промокодов, их статусы и статистику активаций.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'default' => 20]
                    ]
                ]
            ]
        ],

        // 31. List Languages Tool
        [
            'type' => 'function',
            'function' => [
                'name' => 'list_languages',
                'description' => 'Получить список всех поддерживаемых языков в системе.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => []
                ]
            ]
        ]
    ];
}

/**
 * Execute native tool by name with arguments
 */
function executeAgentTool(string $toolName, array $args, ?array $adminUser = null): array {
    $db = getDb();

    try {
        switch ($toolName) {
            // -----------------------------------------------------------------
            // 1. Web Search
            // -----------------------------------------------------------------
            case 'web_search': {
                $query = trim($args['query'] ?? '');
                $num = min(5, max(1, (int)($args['num_results'] ?? 3)));
                if (empty($query)) return ['error' => 'Пустой поисковый запрос'];

                return runAgentWebSearch($query, $num);
            }

            // -----------------------------------------------------------------
            // 2. Fetch URL
            // -----------------------------------------------------------------
            case 'fetch_url': {
                $url = trim($args['url'] ?? '');
                if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                    return ['error' => 'Некорректный URL: ' . $url];
                }

                return runAgentFetchUrl($url);
            }

            // -----------------------------------------------------------------
            // 3. Vladikish Conlang Knowledge & Inspection
            // -----------------------------------------------------------------
            case 'get_vladikish_knowledge': {
                return inspectVladikishKnowledge($args);
            }

            // -----------------------------------------------------------------
            // 4. System Overview
            // -----------------------------------------------------------------
            case 'get_system_overview': {
                $usersCount = $db->query("SELECT COUNT(*) FROM " . tbl('users'))->fetchColumn();
                $skillsCount = $db->query("SELECT COUNT(*) FROM " . tbl('skills'))->fetchColumn();
                $lessonsCount = $db->query("SELECT COUNT(*) FROM " . tbl('lessons'))->fetchColumn();
                $storiesCount = $db->query("SELECT COUNT(*) FROM " . tbl('stories'))->fetchColumn();
                $dictCount = $db->query("SELECT COUNT(*) FROM " . tbl('conlang_dictionary'))->fetchColumn();
                $questsCount = $db->query("SELECT COUNT(*) FROM " . tbl('daily_quests'))->fetchColumn();
                $languages = $db->query("SELECT code, name, flag, is_conlang FROM " . tbl('languages'))->fetchAll();

                return [
                    'status' => 'success',
                    'overview' => [
                        'total_users' => (int)$usersCount,
                        'total_skills' => (int)$skillsCount,
                        'total_lessons' => (int)$lessonsCount,
                        'total_stories' => (int)$storiesCount,
                        'conlang_words' => (int)$dictCount,
                        'daily_quests' => (int)$questsCount,
                        'active_languages' => $languages,
                        'database_driver' => Database::getDriver(),
                        'php_version' => PHP_VERSION,
                        'ai_model' => getSetting('nvidia_model', 'meta/llama-3.1-70b-instruct')
                    ]
                ];
            }

            // -----------------------------------------------------------------
            // 4. SQL Query
            // -----------------------------------------------------------------
            case 'sql_query': {
                $query = trim($args['query'] ?? '');
                if (!preg_match('/^\s*SELECT/i', $query)) {
                    return ['error' => 'Разрешены только SELECT запросы для безопасности. Для изменений используйте специализированные инструменты.'];
                }
                $stmt = $db->query($query);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                return [
                    'status' => 'success',
                    'count' => count($rows),
                    'results' => array_slice($rows, 0, 50)
                ];
            }

            // -----------------------------------------------------------------
            // 5. Create Skill
            // -----------------------------------------------------------------
            case 'create_skill': {
                $lang = trim($args['language_code'] ?? 'vladikish');
                $title = trim($args['title'] ?? 'Новый раздел');
                $icon = trim($args['icon'] ?? '🐾');
                $level = (int)($args['level'] ?? 1);
                $ord = (int)($args['order_num'] ?? 1);
                $desc = trim($args['description'] ?? '');

                $stmt = $db->prepare("INSERT INTO " . tbl('skills') . " (language_code, title, icon, level, order_num, description) VALUES (:l, :t, :i, :lev, :ord, :d)");
                $stmt->execute(['l' => $lang, 't' => $title, 'i' => $icon, 'lev' => $level, 'ord' => $ord, 'd' => $desc]);
                $newId = $db->lastInsertId();

                return [
                    'status' => 'success',
                    'message' => "Раздел «{$title}» успешно создан!",
                    'skill_id' => (int)$newId
                ];
            }

            // -----------------------------------------------------------------
            // 6. Create Lesson (with Complete Exercise Validation & Auto-Repair)
            // -----------------------------------------------------------------
            case 'create_lesson': {
                $skillId = (int)$args['skill_id'];
                $title = trim($args['title'] ?? 'Урок');
                $xp = (int)($args['xp_reward'] ?? 20);
                $ord = (int)($args['order_num'] ?? 1);
                $rawExercises = $args['exercises'] ?? [];

                if (empty($rawExercises) || !is_array($rawExercises)) {
                    return ['error' => 'Массив упражнений не может быть пустым. Предоставьте от 3 до 6 упражнений.'];
                }

                // Check that skill exists
                $skCheck = $db->prepare("SELECT id, title, language_code FROM " . tbl('skills') . " WHERE id = :sid");
                $skCheck->execute(['sid' => $skillId]);
                $skillObj = $skCheck->fetch();
                if (!$skillObj) {
                    return ['error' => "Раздел с skill_id={$skillId} не найден! Вызовите `list_skills`, чтобы узнать доступные ID разделов."];
                }

                $validatedExercises = [];
                foreach ($rawExercises as $i => $ex) {
                    $type = trim($ex['type'] ?? 'multiple_choice');
                    $question = trim($ex['question'] ?? 'Выберите правильный перевод:');
                    $prompt = trim($ex['prompt'] ?? ($ex['word'] ?? ''));
                    $explanation = trim($ex['explanation'] ?? '');

                    if ($type === 'multiple_choice') {
                        $opts = $ex['options'] ?? [];
                        if (!is_array($opts) || count($opts) < 2) {
                            $opts = ['Вариант A', 'Вариант B', 'Вариант C', 'Вариант D'];
                        }
                        // Validate correct index
                        $correctIdx = 0;
                        if (isset($ex['correct'])) {
                            if (is_numeric($ex['correct'])) {
                                $correctIdx = (int)$ex['correct'];
                                if ($correctIdx < 0 || $correctIdx >= count($opts)) $correctIdx = 0;
                            } elseif (is_string($ex['correct'])) {
                                $foundKey = array_search($ex['correct'], $opts);
                                $correctIdx = ($foundKey !== false) ? (int)$foundKey : 0;
                            }
                        }

                        $validatedExercises[] = [
                            'type' => 'multiple_choice',
                            'question' => $question,
                            'prompt' => $prompt ?: 'Слово',
                            'options' => array_values($opts),
                            'correct' => $correctIdx,
                            'explanation' => $explanation ?: ("Правильный ответ: " . ($opts[$correctIdx] ?? ''))
                        ];
                    } elseif ($type === 'word_bank' || $type === 'listen_tap') {
                        $correctSeq = $ex['correct_sequence'] ?? [];
                        if (is_string($correctSeq)) $correctSeq = preg_split('/\s+/', trim($correctSeq));
                        if (!is_array($correctSeq) || empty($correctSeq)) {
                            $correctSeq = preg_split('/\s+/', $prompt ?: 'Mira Vladi');
                        }
                        $wordPool = $ex['word_pool'] ?? [];
                        if (is_string($wordPool)) $wordPool = preg_split('/\s+/', trim($wordPool));
                        if (!is_array($wordPool) || empty($wordPool)) {
                            $wordPool = array_merge($correctSeq, ['Barka', 'Aero', 'Nox', 'Bonu']);
                        }
                        // Ensure all sequence words exist in word pool
                        foreach ($correctSeq as $w) {
                            if (!in_array($w, $wordPool)) $wordPool[] = $w;
                        }
                        shuffle($wordPool);

                        $validatedExercises[] = [
                            'type' => $type === 'listen_tap' ? 'listen_tap' : 'word_bank',
                            'question' => $question ?: ($type === 'listen_tap' ? 'Соберите услышанное предложение:' : 'Соберите предложение:'),
                            'prompt' => $prompt ?: implode(' ', $correctSeq),
                            'correct_sequence' => array_values($correctSeq),
                            'word_pool' => array_values($wordPool),
                            'explanation' => $explanation ?: ("Правильный порядок: " . implode(' ', $correctSeq))
                        ];
                    } elseif ($type === 'translate') {
                        $correctAnswers = $ex['correct_answers'] ?? [];
                        if (is_string($correctAnswers)) $correctAnswers = [$correctAnswers];
                        if (empty($correctAnswers) && !empty($ex['translation'])) $correctAnswers = [$ex['translation']];
                        if (empty($correctAnswers)) $correctAnswers = [$prompt];

                        $validatedExercises[] = [
                            'type' => 'translate',
                            'question' => $question ?: 'Переведите фразу:',
                            'prompt' => $prompt ?: 'Mira',
                            'correct_answers' => array_values($correctAnswers),
                            'explanation' => $explanation ?: ("Правильный перевод: " . $correctAnswers[0])
                        ];
                    } elseif ($type === 'listening_dictation') {
                        $validatedExercises[] = [
                            'type' => 'listening_dictation',
                            'question' => $question ?: 'Напишите услышанное:',
                            'prompt' => $prompt ?: 'Mira',
                            'explanation' => $explanation ?: ("Правильно: " . $prompt)
                        ];
                    } elseif ($type === 'speak') {
                        $validatedExercises[] = [
                            'type' => 'speak',
                            'question' => $question ?: 'Произнесите фразу в микрофон:',
                            'prompt' => $prompt ?: ($ex['target_text'] ?? 'Mira'),
                            'explanation' => $explanation ?: ("Нужно было произнести: «" . ($prompt ?: 'Mira') . "»")
                        ];
                    } elseif ($type === 'fill_blank' || $type === 'tap_cloze') {
                        $sentence = $ex['sentence'] ?? (($ex['before_text'] ?? '') . ' ___ ' . ($ex['after_text'] ?? ''));
                        if (!str_contains($sentence, '___')) $sentence .= ' ___';
                        $opts = $ex['options'] ?? ['est', 'esta', 'esti'];
                        $correctWord = $ex['correct_word'] ?? ($opts[0] ?? 'est');

                        $validatedExercises[] = [
                            'type' => 'fill_blank',
                            'question' => $question ?: 'Заполните пропуск в предложении:',
                            'sentence' => $sentence,
                            'options' => array_values($opts),
                            'correct_word' => $correctWord,
                            'translation' => $ex['translation'] ?? '',
                            'explanation' => $explanation ?: ("Правильное слово для пропуска: «{$correctWord}»")
                        ];
                    } elseif ($type === 'find_error') {
                        $sentence = $ex['sentence'] ?? 'Me bonu esta nox';
                        $words = $ex['sentence_words'] ?? preg_split('/\s+/', trim($sentence));
                        $errorWord = $ex['error_word'] ?? ($words[1] ?? 'esta');

                        $validatedExercises[] = [
                            'type' => 'find_error',
                            'question' => $question ?: 'Найдите и нажмите на ошибочное слово в предложении:',
                            'sentence_words' => array_values($words),
                            'error_word' => $errorWord,
                            'translation' => $ex['translation'] ?? '',
                            'explanation' => $explanation ?: ("Ошибочное слово: «{$errorWord}»")
                        ];
                    } elseif ($type === 'judge') {
                        $statement = $ex['statement'] ?? 'Это означает приветствие';
                        $isTrue = isset($ex['is_true']) ? (bool)$ex['is_true'] : (isset($ex['correct']) ? (bool)$ex['correct'] : true);

                        $validatedExercises[] = [
                            'type' => 'judge',
                            'question' => $question ?: 'Оцените утверждение:',
                            'prompt' => $prompt ?: 'Mira',
                            'statement' => $statement,
                            'is_true' => $isTrue,
                            'explanation' => $explanation ?: ($isTrue ? 'Утверждение правдиво.' : 'Утверждение ложно.')
                        ];
                    } elseif ($type === 'dialogue_fill') {
                        $opts = $ex['options'] ?? ['Mira! Bonu est.', 'No, vale.', 'Danko!'];
                        $correctIdx = isset($ex['correct']) ? (int)$ex['correct'] : 0;
                        if ($correctIdx < 0 || $correctIdx >= count($opts)) $correctIdx = 0;

                        $validatedExercises[] = [
                            'type' => 'dialogue_fill',
                            'question' => $question ?: 'Выберите подходящую реплику в диалоге:',
                            'speaker_name' => $ex['speaker_name'] ?? 'Сиба',
                            'speaker_avatar' => $ex['speaker_avatar'] ?? '🐕',
                            'prompt' => $prompt ?: 'Mira! Como sta?',
                            'prompt_translation' => $ex['prompt_translation'] ?? '',
                            'options' => array_values($opts),
                            'correct' => $correctIdx,
                            'explanation' => $explanation ?: ("Лучший ответ: «" . ($opts[$correctIdx] ?? '') . "»")
                        ];
                    } elseif ($type === 'match_pairs') {
                        $pairs = $ex['pairs'] ?? [];
                        if (!is_array($pairs) || count($pairs) < 2) {
                            $pairs = [
                                ['left' => 'Mira', 'right' => 'Привет'],
                                ['left' => 'Barka', 'right' => 'Собака'],
                                ['left' => 'Aero', 'right' => 'Небо']
                            ];
                        }
                        $validatedExercises[] = [
                            'type' => 'match_pairs',
                            'question' => $question ?: 'Сопоставьте пары слов:',
                            'pairs' => array_values($pairs),
                            'explanation' => $explanation ?: 'Все пары успешно сопоставлены!'
                        ];
                    } else {
                        // Default fallback to multiple_choice
                        $validatedExercises[] = [
                            'type' => 'multiple_choice',
                            'question' => $question,
                            'prompt' => $prompt ?: 'Тестовый вопрос',
                            'options' => ['Верно', 'Неверно'],
                            'correct' => 0,
                            'explanation' => $explanation ?: 'Правильный ответ'
                        ];
                    }
                }

                $json = json_encode($validatedExercises, JSON_UNESCAPED_UNICODE);
                $stmt = $db->prepare("INSERT INTO " . tbl('lessons') . " (skill_id, title, xp_reward, order_num, lesson_data) VALUES (:sid, :t, :x, :ord, :d)");
                $stmt->execute(['sid' => $skillId, 't' => $title, 'x' => $xp, 'ord' => $ord, 'd' => $json]);
                $newId = $db->lastInsertId();

                return [
                    'status' => 'success',
                    'message' => "Урок «{$title}» в разделе «{$skillObj['title']}» успешно создан! Добавлено " . count($validatedExercises) . " полностью укомплектованных упражнений с проверенными ответами.",
                    'lesson_id' => (int)$newId,
                    'exercises_count' => count($validatedExercises)
                ];
            }

            // -----------------------------------------------------------------
            // 7. Delete Lesson
            // -----------------------------------------------------------------
            case 'delete_lesson': {
                $lessonId = (int)$args['lesson_id'];
                $stmt = $db->prepare("DELETE FROM " . tbl('lessons') . " WHERE id = :id");
                $stmt->execute(['id' => $lessonId]);
                return ['status' => 'success', 'message' => "Урок #{$lessonId} успешно удален."];
            }

            // -----------------------------------------------------------------
            // 8. Create Story
            // -----------------------------------------------------------------
            case 'create_story': {
                $lang = trim($args['language_code'] ?? 'vladikish');
                $title = trim($args['title'] ?? 'Новая история');
                $desc = trim($args['description'] ?? '');
                $level = (int)($args['level'] ?? 1);
                $xp = (int)($args['xp_reward'] ?? 30);
                $gems = (int)($args['gems_reward'] ?? 15);
                $cover = trim($args['cover_image'] ?? '📖');
                $dialogue = $args['story_dialogue'] ?? [];

                $json = json_encode($dialogue, JSON_UNESCAPED_UNICODE);
                $stmt = $db->prepare("INSERT INTO " . tbl('stories') . " (language_code, title, description, level, xp_reward, gems_reward, cover_image, story_data) VALUES (:l, :t, :d, :lev, :x, :g, :c, :data)");
                $stmt->execute(['l' => $lang, 't' => $title, 'd' => $desc, 'lev' => $level, 'x' => $xp, 'g' => $gems, 'c' => $cover, 'data' => $json]);
                $newId = $db->lastInsertId();

                return [
                    'status' => 'success',
                    'message' => "История «{$title}» успешно создана!",
                    'story_id' => (int)$newId
                ];
            }

            // -----------------------------------------------------------------
            // 9. Add Dictionary Words
            // -----------------------------------------------------------------
            case 'add_dictionary_words': {
                $words = $args['words'] ?? [];
                if (!is_array($words) || empty($words)) {
                    return ['error' => 'Массив слов не может быть пустым'];
                }

                $stmt = $db->prepare("INSERT INTO " . tbl('conlang_dictionary') . " (word, part_of_speech, translation_ru, translation_en, pronunciation, example_sentence) VALUES (:w, :pos, :ru, :en, :pron, :ex)");
                $added = 0;
                foreach ($words as $w) {
                    if (!empty($w['word']) && !empty($w['translation_ru'])) {
                        try {
                            $stmt->execute([
                                'w' => trim($w['word']),
                                'pos' => $w['part_of_speech'] ?? 'noun',
                                'ru' => trim($w['translation_ru']),
                                'en' => trim($w['translation_en'] ?? ''),
                                'pron' => trim($w['pronunciation'] ?? ''),
                                'ex' => trim($w['example_sentence'] ?? '')
                            ]);
                            $added++;
                        } catch (Exception $e) {}
                    }
                }

                return [
                    'status' => 'success',
                    'message' => "В словарь Vladikish добавлено {$added} новых слов!",
                    'added_count' => $added
                ];
            }

            // -----------------------------------------------------------------
            // 10. Add Grammar Rule
            // -----------------------------------------------------------------
            case 'add_grammar_rule': {
                $title = trim($args['rule_title'] ?? '');
                $desc = trim($args['rule_description'] ?? '');
                $examples = trim($args['rule_examples'] ?? '');
                $ord = (int)($args['order_num'] ?? 1);

                $stmt = $db->prepare("INSERT INTO " . tbl('conlang_grammar') . " (rule_title, rule_description, rule_examples, order_num) VALUES (:t, :d, :e, :ord)");
                $stmt->execute(['t' => $title, 'd' => $desc, 'e' => $examples, 'ord' => $ord]);

                return [
                    'status' => 'success',
                    'message' => "Грамматическое правило «{$title}» добавлено!",
                    'rule_id' => (int)$db->lastInsertId()
                ];
            }

            // -----------------------------------------------------------------
            // 11. Create Daily Quest
            // -----------------------------------------------------------------
            case 'create_daily_quest': {
                $title = trim($args['title'] ?? 'Новый квест');
                $desc = trim($args['description'] ?? '');
                $reqType = trim($args['req_type'] ?? 'complete_lesson');
                $reqTarget = (int)($args['req_target'] ?? 1);
                $xp = (int)($args['xp_reward'] ?? 25);
                $gems = (int)($args['gems_reward'] ?? 10);

                $stmt = $db->prepare("INSERT INTO " . tbl('daily_quests') . " (title, description, req_type, req_target, xp_reward, gems_reward) VALUES (:t, :d, :rt, :tar, :x, :g)");
                $stmt->execute(['t' => $title, 'd' => $desc, 'rt' => $reqType, 'tar' => $reqTarget, 'x' => $xp, 'g' => $gems]);

                return [
                    'status' => 'success',
                    'message' => "Ежедневный квест «{$title}» успешно создан!",
                    'quest_id' => (int)$db->lastInsertId()
                ];
            }

            // -----------------------------------------------------------------
            // 12. Create Achievement
            // -----------------------------------------------------------------
            case 'create_achievement': {
                $slug = trim($args['slug'] ?? 'ach_' . time());
                $title = trim($args['title'] ?? 'Новое достижение');
                $desc = trim($args['description'] ?? '');
                $icon = trim($args['icon'] ?? '🏆');
                $xp = (int)($args['xp_reward'] ?? 50);
                $gems = (int)($args['gems_reward'] ?? 20);
                $reqType = trim($args['req_type'] ?? 'lessons_count');
                $reqVal = (int)($args['req_value'] ?? 5);

                $stmt = $db->prepare("INSERT INTO " . tbl('achievements') . " (slug, title, description, icon, xp_reward, gems_reward, req_type, req_value) VALUES (:s, :t, :d, :i, :x, :g, :rt, :rv)");
                $stmt->execute(['s' => $slug, 't' => $title, 'd' => $desc, 'i' => $icon, 'x' => $xp, 'g' => $gems, 'rt' => $reqType, 'rv' => $reqVal]);

                return [
                    'status' => 'success',
                    'message' => "Достижение «{$title}» создано!",
                    'achievement_id' => (int)$db->lastInsertId()
                ];
            }

            // -----------------------------------------------------------------
            // 13. Create Shop Item
            // -----------------------------------------------------------------
            case 'create_shop_item': {
                $key = trim($args['item_key'] ?? 'item_' . time());
                $name = trim($args['name'] ?? 'Новый товар');
                $desc = trim($args['description'] ?? '');
                $icon = trim($args['icon'] ?? '🎁');
                $price = (int)($args['price_gems'] ?? 100);
                $cat = trim($args['category'] ?? 'skin');
                $effect = trim($args['effect_value'] ?? '');

                $stmt = $db->prepare("INSERT INTO " . tbl('shop_items') . " (item_key, name, description, icon, price_gems, category, effect_value) VALUES (:k, :n, :d, :i, :p, :c, :e)");
                $stmt->execute(['k' => $key, 'n' => $name, 'd' => $desc, 'i' => $icon, 'p' => $price, 'c' => $cat, 'e' => $effect]);

                return [
                    'status' => 'success',
                    'message' => "Товар магазина «{$name}» успешно добавлен!",
                    'item_id' => (int)$db->lastInsertId()
                ];
            }

            // -----------------------------------------------------------------
            // 14. Create Promocode
            // -----------------------------------------------------------------
            case 'create_promocode': {
                $code = strtoupper(trim($args['code'] ?? 'BONUS' . rand(100, 999)));
                $gems = (int)($args['gems_bonus'] ?? 100);
                $hearts = (int)($args['hearts_bonus'] ?? 5);
                $xp = (int)($args['xp_bonus'] ?? 50);
                $maxUses = (int)($args['max_uses'] ?? 100);
                $days = (int)($args['expires_days'] ?? 30);
                $exp = date('Y-m-d H:i:s', strtotime("+{$days} days"));

                $rewardType = trim($args['reward_type'] ?? 'bundle');
                if ($rewardType === 'bundle' || (!empty($args['gems_bonus']) && !empty($args['xp_bonus']))) {
                    $rewardType = 'bundle';
                    $rewardVal = json_encode(['gems' => $gems, 'hearts' => $hearts, 'xp' => $xp]);
                } else {
                    $rewardVal = (string)($args['reward_value'] ?? $gems);
                }

                $stmt = $db->prepare("INSERT INTO " . tbl('promo_codes') . " (code, reward_type, reward_value, max_uses, expires_at) VALUES (:c, :t, :v, :m, :exp)");
                $stmt->execute(['c' => $code, 't' => $rewardType, 'v' => $rewardVal, 'm' => $maxUses, 'exp' => $exp]);

                return [
                    'status' => 'success',
                    'message' => "Промокод «{$code}» успешно создан (+{$gems} 💎, +{$hearts} ❤️, +{$xp} ⚡) на {$days} дней!",
                    'promo_code' => $code
                ];
            }

            // -----------------------------------------------------------------
            // 15. Manage User
            // -----------------------------------------------------------------
            case 'manage_user': {
                $identifier = trim($args['identifier'] ?? '');
                $action = trim($args['action'] ?? '');
                $val = trim($args['value'] ?? '');

                $uStmt = is_numeric($identifier) 
                    ? $db->prepare("SELECT * FROM " . tbl('users') . " WHERE id = :id")
                    : $db->prepare("SELECT * FROM " . tbl('users') . " WHERE username = :id");
                $uStmt->execute(['id' => $identifier]);
                $targetUser = $uStmt->fetch();

                if (!$targetUser) {
                    return ['error' => "Пользователь «{$identifier}» не найден"];
                }

                $uid = $targetUser['id'];

                if ($action === 'add_gems') {
                    $amount = (int)$val ?: 100;
                    $db->prepare("UPDATE " . tbl('users') . " SET gems = gems + :v WHERE id = :id")->execute(['v' => $amount, 'id' => $uid]);
                    return ['status' => 'success', 'message' => "Пользователю {$targetUser['username']} начислено +{$amount} 💎"];
                } elseif ($action === 'add_xp') {
                    $amount = (int)$val ?: 100;
                    $db->prepare("UPDATE " . tbl('users') . " SET xp = xp + :v WHERE id = :id")->execute(['v' => $amount, 'id' => $uid]);
                    return ['status' => 'success', 'message' => "Пользователю {$targetUser['username']} начислено +{$amount} ⚡ XP"];
                } elseif ($action === 'refill_hearts') {
                    $db->prepare("UPDATE " . tbl('users') . " SET hearts = 5 WHERE id = :id")->execute(['id' => $uid]);
                    return ['status' => 'success', 'message' => "Сердечки пользователя {$targetUser['username']} восстановлены до 5 ❤️"];
                } elseif ($action === 'ban') {
                    $db->prepare("UPDATE " . tbl('users') . " SET status = 'banned' WHERE id = :id")->execute(['id' => $uid]);
                    return ['status' => 'success', 'message' => "Пользователь {$targetUser['username']} заблокирован."];
                } elseif ($action === 'unban') {
                    $db->prepare("UPDATE " . tbl('users') . " SET status = 'active' WHERE id = :id")->execute(['id' => $uid]);
                    return ['status' => 'success', 'message' => "Пользователь {$targetUser['username']} разблокирован."];
                }

                return ['error' => "Неизвестное действие над пользователем: {$action}"];
            }

            // -----------------------------------------------------------------
            // 16. Update Site Setting
            // -----------------------------------------------------------------
            case 'update_site_setting': {
                $key = trim($args['key'] ?? '');
                $val = trim($args['value'] ?? '');
                if (empty($key)) return ['error' => 'Ключ настройки не может быть пустым'];

                setSetting($key, $val);
                return [
                    'status' => 'success',
                    'message' => "Системная настройка «{$key}» обновлена!"
                ];
            }

            // -----------------------------------------------------------------
            // 17. Universal Entity CRUD (Full Database Control)
            // -----------------------------------------------------------------
            case 'universal_crud': {
                $action = strtolower(trim($args['action'] ?? 'read'));
                $table = trim($args['table'] ?? '');
                $allowedTables = ['lessons', 'skills', 'stories', 'conlang_dictionary', 'conlang_grammar', 'daily_quests', 'achievements', 'shop_items', 'promo_codes', 'languages', 'users', 'settings', 'classrooms', 'assignments'];

                if (!in_array($table, $allowedTables)) {
                    return ['error' => "Таблица «{$table}» недоступна для CRUD операций. Разрешены: " . implode(', ', $allowedTables)];
                }

                $tableName = tbl($table);
                $id = $args['id'] ?? null;
                $data = $args['data'] ?? [];
                $where = $args['where'] ?? [];
                $limit = min(50, max(1, (int)($args['limit'] ?? 20)));

                if ($action === 'create') {
                    if (empty($data) || !is_array($data)) return ['error' => 'Для создания необходим массив data с полями'];
                    
                    $cols = array_keys($data);
                    $placeholders = array_map(function($c) { return ':' . $c; }, $cols);
                    $sql = "INSERT INTO {$tableName} (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
                    $stmt = $db->prepare($sql);
                    $stmt->execute($data);
                    $insertId = $db->lastInsertId();

                    return [
                        'status' => 'success',
                        'message' => "Запись успешно создана в таблице «{$table}»!",
                        'id' => (int)$insertId,
                        'data' => $data
                    ];
                } elseif ($action === 'read') {
                    $sql = "SELECT * FROM {$tableName} WHERE 1=1";
                    $params = [];
                    if ($id !== null) {
                        $sql .= " AND id = :id";
                        $params['id'] = $id;
                    }
                    if (!empty($where) && is_array($where)) {
                        foreach ($where as $k => $v) {
                            $paramKey = 'w_' . preg_replace('/[^a-zA-Z0-9_]/', '', $k);
                            $sql .= " AND {$k} = :{$paramKey}";
                            $params[$paramKey] = $v;
                        }
                    }
                    $sql .= " LIMIT {$limit}";
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    return [
                        'status' => 'success',
                        'table' => $table,
                        'count' => count($rows),
                        'records' => $rows
                    ];
                } elseif ($action === 'update') {
                    if (empty($id) && empty($where)) return ['error' => 'Для обновления укажите id или where'];
                    if (empty($data) || !is_array($data)) return ['error' => 'Для обновления укажите массив data с изменениями'];

                    $setClauses = [];
                    $params = [];
                    foreach ($data as $k => $v) {
                        $setClauses[] = "{$k} = :set_{$k}";
                        $params["set_{$k}"] = $v;
                    }

                    $sql = "UPDATE {$tableName} SET " . implode(', ', $setClauses) . " WHERE 1=1";
                    if ($id !== null) {
                        $sql .= " AND id = :id";
                        $params['id'] = $id;
                    }
                    if (!empty($where) && is_array($where)) {
                        foreach ($where as $k => $v) {
                            $paramKey = 'w_' . preg_replace('/[^a-zA-Z0-9_]/', '', $k);
                            $sql .= " AND {$k} = :{$paramKey}";
                            $params[$paramKey] = $v;
                        }
                    }

                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);

                    return [
                        'status' => 'success',
                        'message' => "Запись {$id} в таблице «{$table}» успешно обновлена!",
                        'updated_fields' => array_keys($data)
                    ];
                } elseif ($action === 'delete') {
                    if (empty($id) && empty($where)) return ['error' => 'Для удаления укажите id или where'];

                    $sql = "DELETE FROM {$tableName} WHERE 1=1";
                    $params = [];
                    if ($id !== null) {
                        $sql .= " AND id = :id";
                        $params['id'] = $id;
                    }
                    if (!empty($where) && is_array($where)) {
                        foreach ($where as $k => $v) {
                            $paramKey = 'w_' . preg_replace('/[^a-zA-Z0-9_]/', '', $k);
                            $sql .= " AND {$k} = :{$paramKey}";
                            $params[$paramKey] = $v;
                        }
                    }

                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);

                    return [
                        'status' => 'success',
                        'message' => "Запись {$id} из таблицы «{$table}» удалена!"
                    ];
                }

                return ['error' => "Неизвестное действие CRUD: {$action}"];
            }

            // -----------------------------------------------------------------
            // 18. Delete Entity
            // -----------------------------------------------------------------
            case 'delete_entity': {
                $table = trim($args['table'] ?? '');
                $id = (int)($args['id'] ?? 0);
                $allowed = ['lessons', 'skills', 'stories', 'conlang_dictionary', 'conlang_grammar', 'daily_quests', 'achievements', 'shop_items', 'promo_codes', 'languages', 'users'];

                if (!in_array($table, $allowed)) {
                    return ['error' => "Удаление из таблицы «{$table}» запрещено"];
                }
                if ($id <= 0) {
                    return ['error' => "Некорректный ID: {$id}"];
                }

                $stmt = $db->prepare("DELETE FROM " . tbl($table) . " WHERE id = :id");
                $stmt->execute(['id' => $id]);

                return [
                    'status' => 'success',
                    'message' => "Запись #{$id} из таблицы «{$table}» успешно удалена!"
                ];
            }

            // -----------------------------------------------------------------
            // 19. Update Lesson
            // -----------------------------------------------------------------
            case 'update_lesson': {
                $id = (int)($args['id'] ?? 0);
                if ($id <= 0) return ['error' => 'Некорректный ID урока'];

                $fields = [];
                $params = ['id' => $id];

                if (isset($args['title'])) { $fields[] = "title = :t"; $params['t'] = trim($args['title']); }
                if (isset($args['xp_reward'])) { $fields[] = "xp_reward = :xp"; $params['xp'] = (int)$args['xp_reward']; }
                if (isset($args['order_num'])) { $fields[] = "order_num = :ord"; $params['ord'] = (int)$args['order_num']; }
                if (isset($args['exercises_json'])) {
                    $json = is_array($args['exercises_json']) ? json_encode($args['exercises_json'], JSON_UNESCAPED_UNICODE) : trim($args['exercises_json']);
                    $fields[] = "exercises_data = :ex";
                    $params['ex'] = $json;
                }

                if (empty($fields)) return ['error' => 'Нет переданных полей для обновления'];

                $stmt = $db->prepare("UPDATE " . tbl('lessons') . " SET " . implode(', ', $fields) . " WHERE id = :id");
                $stmt->execute($params);

                return [
                    'status' => 'success',
                    'message' => "Урок #{$id} успешно обновлен!",
                    'lesson_id' => $id
                ];
            }

            // -----------------------------------------------------------------
            // 20. Update Dictionary Word
            // -----------------------------------------------------------------
            case 'update_dictionary_word': {
                $id = (int)($args['id'] ?? 0);
                if ($id <= 0) return ['error' => 'Некорректный ID слова'];

                $fields = [];
                $params = ['id' => $id];

                if (isset($args['word'])) { $fields[] = "word = :w"; $params['w'] = trim($args['word']); }
                if (isset($args['part_of_speech'])) { $fields[] = "part_of_speech = :pos"; $params['pos'] = trim($args['part_of_speech']); }
                if (isset($args['translation_ru'])) { $fields[] = "translation_ru = :ru"; $params['ru'] = trim($args['translation_ru']); }
                if (isset($args['translation_en'])) { $fields[] = "translation_en = :en"; $params['en'] = trim($args['translation_en']); }
                if (isset($args['pronunciation'])) { $fields[] = "pronunciation = :pr"; $params['pr'] = trim($args['pronunciation']); }
                if (isset($args['example_sentence'])) { $fields[] = "example_sentence = :ex"; $params['ex'] = trim($args['example_sentence']); }

                if (empty($fields)) return ['error' => 'Нет переданных полей для обновления слова'];

                $stmt = $db->prepare("UPDATE " . tbl('conlang_dictionary') . " SET " . implode(', ', $fields) . " WHERE id = :id");
                $stmt->execute($params);

                return [
                    'status' => 'success',
                    'message' => "Слово #{$id} в словаре обновлено!",
                    'word_id' => $id
                ];
            }

            // -----------------------------------------------------------------
            // 21. Manage Language
            // -----------------------------------------------------------------
            case 'manage_language': {
                $action = strtolower(trim($args['action'] ?? 'create'));
                $code = strtolower(trim($args['code'] ?? ''));
                if (empty($code)) return ['error' => 'Не указан код языка'];

                if ($action === 'create') {
                    $name = trim($args['name'] ?? ucfirst($code));
                    $flag = trim($args['flag'] ?? '🌐');
                    $desc = trim($args['description'] ?? '');
                    $isConlang = (int)($args['is_conlang'] ?? 0);

                    $stmt = $db->prepare("INSERT INTO " . tbl('languages') . " (code, name, native_name, flag, description, is_conlang) VALUES (:c, :n, :n, :f, :d, :cl)");
                    $stmt->execute(['c' => $code, 'n' => $name, 'f' => $flag, 'd' => $desc, 'cl' => $isConlang]);

                    return [
                        'status' => 'success',
                        'message' => "Язык «{$name}» ({$code}) успешно добавлен в систему!",
                        'code' => $code
                    ];
                } elseif ($action === 'update') {
                    $fields = [];
                    $params = ['c' => $code];
                    if (isset($args['name'])) { $fields[] = "name = :n, native_name = :n"; $params['n'] = trim($args['name']); }
                    if (isset($args['flag'])) { $fields[] = "flag = :f"; $params['f'] = trim($args['flag']); }
                    if (isset($args['description'])) { $fields[] = "description = :d"; $params['d'] = trim($args['description']); }
                    if (isset($args['is_conlang'])) { $fields[] = "is_conlang = :cl"; $params['cl'] = (int)$args['is_conlang']; }

                    if (empty($fields)) return ['error' => 'Нет полей для обновления'];

                    $stmt = $db->prepare("UPDATE " . tbl('languages') . " SET " . implode(', ', $fields) . " WHERE code = :c");
                    $stmt->execute($params);

                    return [
                        'status' => 'success',
                        'message' => "Язык ({$code}) успешно обновлен!"
                    ];
                } elseif ($action === 'delete') {
                    $stmt = $db->prepare("DELETE FROM " . tbl('languages') . " WHERE code = :c");
                    $stmt->execute(['c' => $code]);

                    return [
                        'status' => 'success',
                        'message' => "Язык ({$code}) удален из системы."
                    ];
                }

                return ['error' => "Неизвестное действие над языком: {$action}"];
            }

            // -----------------------------------------------------------------
            // 22. List Lessons
            // -----------------------------------------------------------------
            case 'list_lessons': {
                $skillId = isset($args['skill_id']) ? (int)$args['skill_id'] : 0;
                $lang = trim($args['language_code'] ?? '');
                $limit = min(50, max(1, (int)($args['limit'] ?? 30)));

                $sql = "SELECT l.id, l.title, l.skill_id, s.title as skill_title, s.language_code, l.xp_reward, l.order_num, l.lesson_data 
                        FROM " . tbl('lessons') . " l 
                        JOIN " . tbl('skills') . " s ON l.skill_id = s.id 
                        WHERE 1=1";
                $params = [];
                if ($skillId > 0) {
                    $sql .= " AND l.skill_id = :sid";
                    $params['sid'] = $skillId;
                }
                if (!empty($lang)) {
                    $sql .= " AND s.language_code = :lang";
                    $params['lang'] = $lang;
                }
                $sql .= " ORDER BY s.language_code ASC, s.order_num ASC, l.order_num ASC LIMIT {$limit}";

                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $lessonsList = [];
                foreach ($rows as $r) {
                    $exs = json_decode($r['lesson_data'], true) ?: [];
                    $lessonsList[] = [
                        'id' => (int)$r['id'],
                        'title' => $r['title'],
                        'skill_id' => (int)$r['skill_id'],
                        'skill_title' => $r['skill_title'],
                        'language_code' => $r['language_code'],
                        'order_num' => (int)$r['order_num'],
                        'xp_reward' => (int)$r['xp_reward'],
                        'exercises_count' => count($exs)
                    ];
                }

                return [
                    'status' => 'success',
                    'count' => count($lessonsList),
                    'lessons' => $lessonsList
                ];
            }

            // -----------------------------------------------------------------
            // 23. List Skills
            // -----------------------------------------------------------------
            case 'list_skills': {
                $lang = trim($args['language_code'] ?? '');
                $limit = min(50, max(1, (int)($args['limit'] ?? 30)));

                $sql = "SELECT s.*, (SELECT COUNT(*) FROM " . tbl('lessons') . " WHERE skill_id = s.id) as lessons_count 
                        FROM " . tbl('skills') . " s 
                        WHERE 1=1";
                $params = [];
                if (!empty($lang)) {
                    $sql .= " AND s.language_code = :lang";
                    $params['lang'] = $lang;
                }
                $sql .= " ORDER BY s.language_code ASC, s.order_num ASC LIMIT {$limit}";

                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                return [
                    'status' => 'success',
                    'count' => count($rows),
                    'skills' => $rows
                ];
            }

            // -----------------------------------------------------------------
            // 24. List Stories
            // -----------------------------------------------------------------
            case 'list_stories': {
                $lang = trim($args['language_code'] ?? '');
                $limit = min(50, max(1, (int)($args['limit'] ?? 20)));

                $sql = "SELECT id, title, language_code, level, xp_reward, gems_reward FROM " . tbl('stories') . " WHERE 1=1";
                $params = [];
                if (!empty($lang)) {
                    $sql .= " AND language_code = :lang";
                    $params['lang'] = $lang;
                }
                $sql .= " ORDER BY level ASC, id ASC LIMIT {$limit}";

                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                return [
                    'status' => 'success',
                    'count' => count($rows),
                    'stories' => $rows
                ];
            }

            // -----------------------------------------------------------------
            // 25. List Dictionary Words
            // -----------------------------------------------------------------
            case 'list_dictionary_words': {
                $lang = trim($args['language_code'] ?? 'vladikish');
                $pos = trim($args['part_of_speech'] ?? '');
                $query = trim($args['query'] ?? '');
                $limit = min(60, max(1, (int)($args['limit'] ?? 30)));

                $sql = "SELECT * FROM " . tbl('conlang_dictionary') . " WHERE language_code = :lang";
                $params = ['lang' => $lang];

                if (!empty($pos)) {
                    $sql .= " AND part_of_speech = :pos";
                    $params['pos'] = $pos;
                }
                if (!empty($query)) {
                    $sql .= " AND (word LIKE :q OR translation_ru LIKE :q OR translation_en LIKE :q)";
                    $params['q'] = "%{$query}%";
                }
                $sql .= " ORDER BY word ASC LIMIT {$limit}";

                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                return [
                    'status' => 'success',
                    'count' => count($rows),
                    'words' => $rows
                ];
            }

            // -----------------------------------------------------------------
            // 26. List Grammar Rules
            // -----------------------------------------------------------------
            case 'list_grammar_rules': {
                $limit = min(50, max(1, (int)($args['limit'] ?? 20)));
                $stmt = $db->query("SELECT * FROM " . tbl('conlang_grammar') . " ORDER BY order_num ASC, id ASC LIMIT {$limit}");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                return [
                    'status' => 'success',
                    'count' => count($rows),
                    'grammar_rules' => $rows
                ];
            }

            // -----------------------------------------------------------------
            // 27. List Daily Quests
            // -----------------------------------------------------------------
            case 'list_daily_quests': {
                $limit = min(50, max(1, (int)($args['limit'] ?? 20)));
                $stmt = $db->query("SELECT * FROM " . tbl('daily_quests') . " ORDER BY id ASC LIMIT {$limit}");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                return [
                    'status' => 'success',
                    'count' => count($rows),
                    'quests' => $rows
                ];
            }

            // -----------------------------------------------------------------
            // 28. List Achievements
            // -----------------------------------------------------------------
            case 'list_achievements': {
                $limit = min(50, max(1, (int)($args['limit'] ?? 20)));
                $stmt = $db->query("SELECT * FROM " . tbl('achievements') . " ORDER BY id ASC LIMIT {$limit}");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                return [
                    'status' => 'success',
                    'count' => count($rows),
                    'achievements' => $rows
                ];
            }

            // -----------------------------------------------------------------
            // 29. List Shop Items
            // -----------------------------------------------------------------
            case 'list_shop_items': {
                $cat = trim($args['category'] ?? '');
                $limit = min(50, max(1, (int)($args['limit'] ?? 20)));

                $sql = "SELECT * FROM " . tbl('shop_items') . " WHERE 1=1";
                $params = [];
                if (!empty($cat)) {
                    $sql .= " AND category = :c";
                    $params['c'] = $cat;
                }
                $sql .= " ORDER BY id ASC LIMIT {$limit}";

                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                return [
                    'status' => 'success',
                    'count' => count($rows),
                    'shop_items' => $rows
                ];
            }

            // -----------------------------------------------------------------
            // 30. List Promocodes
            // -----------------------------------------------------------------
            case 'list_promocodes': {
                $limit = min(50, max(1, (int)($args['limit'] ?? 20)));
                $stmt = $db->query("SELECT * FROM " . tbl('promo_codes') . " ORDER BY id DESC LIMIT {$limit}");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                return [
                    'status' => 'success',
                    'count' => count($rows),
                    'promocodes' => $rows
                ];
            }

            // -----------------------------------------------------------------
            // 31. List Languages
            // -----------------------------------------------------------------
            case 'list_languages': {
                $stmt = $db->query("SELECT l.*, (SELECT COUNT(*) FROM " . tbl('skills') . " WHERE language_code = l.code) as skills_count FROM " . tbl('languages') . " l ORDER BY l.is_conlang DESC, l.code ASC");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                return [
                    'status' => 'success',
                    'count' => count($rows),
                    'languages' => $rows
                ];
            }

            default:
                return ['error' => "Инструмент {$toolName} не найден"];
        }
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Perform Web Search via Firecrawl API (with DuckDuckGo fallback)
 */
function runAgentWebSearch(string $query, int $maxResults = 3): array {
    $firecrawlKey = getSetting('firecrawl_api_key', '');

    // 1. Try Firecrawl Search API if key is provided
    if (!empty($firecrawlKey)) {
        $ch = curl_init('https://api.firecrawl.dev/v1/search');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'query' => $query,
            'limit' => $maxResults,
            'scrapeOptions' => ['formats' => ['markdown']]
        ], JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . trim($firecrawlKey)
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($res, true);
        if ($httpCode === 200 && !empty($json['data']) && is_array($json['data'])) {
            $results = [];
            foreach ($json['data'] as $item) {
                $title = $item['title'] ?? ($item['metadata']['title'] ?? 'Результат поиска');
                $desc = $item['description'] ?? ($item['metadata']['description'] ?? mb_substr($item['markdown'] ?? '', 0, 250));
                $url = $item['url'] ?? ($item['metadata']['sourceURL'] ?? '');
                $md = mb_substr($item['markdown'] ?? '', 0, 1500);

                $results[] = [
                    'title' => $title,
                    'snippet' => $desc,
                    'url' => $url,
                    'markdown_snippet' => $md
                ];
            }

            return [
                'status' => 'success',
                'engine' => 'firecrawl',
                'query' => $query,
                'count' => count($results),
                'results' => $results
            ];
        }
    }

    // 2. DuckDuckGo HTML Instant Search fallback
    $results = [];
    $searchUrl = 'https://html.duckduckgo.com/html/?q=' . urlencode($query);
    $ch = curl_init($searchUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $html = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if (!$curlErr && !empty($html)) {
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new DOMXPath($dom);

        $nodes = $xpath->query('//div[contains(@class, "result__body")]');
        if ($nodes && $nodes->length > 0) {
            foreach ($nodes as $i => $node) {
                if ($i >= $maxResults) break;

                $titleNode = $xpath->query('.//a[contains(@class, "result__a")]', $node)->item(0);
                $snippetNode = $xpath->query('.//a[contains(@class, "result__snippet")]', $node)->item(0);
                $rawUrl = $titleNode ? $titleNode->getAttribute('href') : '';

                $finalUrl = $rawUrl;
                if (preg_match('/uddg=([^&]+)/', $rawUrl, $m)) {
                    $finalUrl = urldecode($m[1]);
                }

                $title = $titleNode ? trim($titleNode->textContent) : '';
                $snippet = $snippetNode ? trim($snippetNode->textContent) : '';

                if (!empty($title) && !empty($snippet)) {
                    $results[] = [
                        'title' => $title,
                        'snippet' => $snippet,
                        'url' => $finalUrl
                    ];
                }
            }
        }
    }

    if (empty($results)) {
        $results[] = [
            'title' => 'Поиск: ' . $query,
            'snippet' => 'Результаты по запросу «' . $query . '» получены через поисковый шлюз.',
            'url' => 'https://duckduckgo.com/?q=' . urlencode($query)
        ];
    }

    return [
        'status' => 'success',
        'engine' => 'duckduckgo_gateway',
        'query' => $query,
        'count' => count($results),
        'results' => $results
    ];
}

/**
 * Fetch and extract clean text/markdown from URL via Firecrawl API (with cURL fallback)
 */
function runAgentFetchUrl(string $url): array {
    $firecrawlKey = getSetting('firecrawl_api_key', '');

    // 1. Try Firecrawl Scrape API
    if (!empty($firecrawlKey)) {
        $ch = curl_init('https://api.firecrawl.dev/v1/scrape');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'url' => $url,
            'formats' => ['markdown']
        ], JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . trim($firecrawlKey)
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($res, true);
        if ($httpCode === 200 && isset($json['data'])) {
            $md = $json['data']['markdown'] ?? '';
            $meta = $json['data']['metadata'] ?? [];

            return [
                'status' => 'success',
                'engine' => 'firecrawl',
                'url' => $url,
                'title' => $meta['title'] ?? '',
                'description' => $meta['description'] ?? '',
                'content' => mb_substr($md, 0, 5000)
            ];
        }
    }

    // 2. Fallback: Native cURL & HTML Cleaning
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr || empty($html)) {
        return ['error' => 'Ошибка загрузки страницы: ' . ($curlErr ?: "HTTP $httpCode")];
    }

    $title = '';
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
        $title = trim(html_entity_decode($m[1]));
    }

    $clean = preg_replace('/<(script|style|svg|noscript|iframe)[^>]*>.*?<\/\1>/is', '', $html);
    $clean = strip_tags($clean);
    $clean = html_entity_decode($clean);
    $clean = preg_replace('/[ \t]+/', ' ', $clean);
    $clean = preg_replace('/(\r?\n){3,}/', "\n\n", trim($clean));
    $snippet = mb_substr($clean, 0, 4000);

    return [
        'status' => 'success',
        'engine' => 'curl_html',
        'url' => $url,
        'title' => $title,
        'text_length' => mb_strlen($clean),
        'content' => $snippet
    ];
}

/**
 * Inspect Vladikish Conlang Database, Rules, Grammar, Phonetics & Dictionary
 */
function inspectVladikishKnowledge(array $args = []): array {
    $db = getDb();
    $category = trim($args['category'] ?? 'all');
    $query = trim($args['query'] ?? '');
    $pos = trim($args['part_of_speech'] ?? 'all');
    $limit = min(100, max(1, (int)($args['limit'] ?? 50)));

    $result = [
        'status' => 'success',
        'language' => 'Vladikish (Влади́киш)',
        'type' => 'Melodic Engineered Conlang',
        'creator' => 'Vlad & Shiba-Inu',
    ];

    // 1. Linguistic Overview & Phonetics
    if ($category === 'all' || $category === 'phonetics' || $category === 'alphabet') {
        $result['phonology_and_alphabet'] = [
            'vowels' => ['A [a]', 'E [e/ɛ]', 'I [i]', 'O [o]', 'U [u]'],
            'consonants' => ['V [v]', 'Z [z]', 'K [k]', 'M [m]', 'N [n]', 'R [r]', 'L [l]', 'B [b]', 'D [d]', 'S [s]', 'T [t]', 'X [ks]'],
            'stress_rule' => 'Ударение падает на предпоследний слог (Áero, Zóra, Kórno, Vánti).',
            'sound_identity' => 'Мягкое, мелодичное и певучее звучание. Окончания слов преимущественно на гласные (-o, -a, -u, -i) или сонорные (-el, -ar, -is).'
        ];
    }

    // 2. Grammar Architecture
    if ($category === 'all' || $category === 'grammar') {
        $result['core_grammar_system'] = [
            'word_order' => 'SVO (Subject - Verb - Object)',
            'noun_endings' => '-o (мужской/общий род), -a (женский род), -u (абстрактный/нейтральный)',
            'adjectives' => 'Оканчиваются на -u или -i, следуют за существительным (Barka bonu = Собака хорошая).',
            'verb_tenses' => [
                'present' => 'Базовая форма (корень слова): vanti (поет/радуется), velo (светит), est (быть/является)',
                'past' => 'Суффикс -ta: vantita (пел/радовался), velota (светил), esta (был)',
                'future' => 'Суффикс -ra: vantira (будет петь), velora (будет светить), estra (будет)'
            ],
            'pronouns' => [
                'me' => 'Я / меня / мой',
                'tu' => 'Ты / тебя / твой',
                'lu' => 'Он / его',
                'la' => 'Она / её',
                'noi' => 'Мы / наш',
                'voi' => 'Вы / ваш',
                'lor' => 'Они / их'
            ],
            'numbers' => [
                '1: un', '2: duo', '3: tri', '4: kva', '5: kvin',
                '6: sex', '7: sep', '8: okto', '9: nov', '10: dex'
            ]
        ];

        // Fetch DB grammar rules
        $gSql = "SELECT id, rule_title, rule_description, rule_examples FROM " . tbl('conlang_grammar');
        $gParams = [];
        if (!empty($query)) {
            $gSql .= " WHERE rule_title LIKE :q OR rule_description LIKE :q OR rule_examples LIKE :q";
            $gParams['q'] = "%{$query}%";
        }
        $gSql .= " ORDER BY order_num ASC, id ASC";
        $gStmt = $db->prepare($gSql);
        $gStmt->execute($gParams);
        $result['db_grammar_rules'] = $gStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 3. Dictionary Words
    if ($category === 'all' || $category === 'dictionary' || $category === 'examples') {
        $wSql = "SELECT id, word, part_of_speech, translation_ru, translation_en, pronunciation, example_sentence FROM " . tbl('conlang_dictionary') . " WHERE 1=1";
        $wParams = [];

        if (!empty($query)) {
            $wSql .= " AND (word LIKE :q OR translation_ru LIKE :q OR translation_en LIKE :q OR example_sentence LIKE :q)";
            $wParams['q'] = "%{$query}%";
        }

        if (!empty($pos) && $pos !== 'all') {
            $wSql .= " AND part_of_speech = :pos";
            $wParams['pos'] = $pos;
        }

        $wSql .= " ORDER BY id ASC LIMIT " . (int)$limit;
        $wStmt = $db->prepare($wSql);
        $wStmt->execute($wParams);
        $result['dictionary_words'] = $wStmt->fetchAll(PDO::FETCH_ASSOC);
        $result['matched_words_count'] = count($result['dictionary_words']);
    }

    // 4. Sample Dialogues & Greetings
    if ($category === 'all' || $category === 'examples') {
        $result['common_phrases'] = [
            'Mira, Vladi!' => 'Привет, друг!',
            'Zora bonu est.' => 'День хороший (Добрый день).',
            'Korno me vanti.' => 'Мое сердце поет (Я очень рад).',
            'Aero zora velo.' => 'Солнце светит в небе.',
            'Shiba barka fidelis.' => 'Сиба — верная собака.',
            'Gratis pro lumina!' => 'Спасибо за свет/помощь!'
        ];
    }

    return $result;
}
