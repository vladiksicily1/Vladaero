<?php
/**
 * ShibaLingo - Deterministic Rule-Based Linguistic Translator Engine (RBMT 2.0)
 * Fully offline, 0ms latency, zero external API/AI dependencies.
 *
 * Implements:
 * 1. Vladikish Morphological Decomposition (Vo-, -ti, -s/-as/-os, -u, -ita, -e, -a).
 * 2. Russian & English Lemmatization and Feature Extraction (Past, Future, Plural, Imperative, Adverb, Diminutive).
 * 3. Smart Dictionary Article Parsing & Synonym Indexing (stripping notes, selecting clean primary meaning).
 * 4. Grammatical Particles & Sentence Structure (Negation 'No', Questions 'An', Genitive 'De').
 * 5. Safe multi-word phrase & idiom masking with casing preservation.
 */

require_once __DIR__ . '/functions.php';

class RuleBasedTranslator {
    private static ?array $dbDictionary = null;

    /**
     * Core multi-word idioms and conversational phrases
     */
    private static array $phrases = [
        [
            'ru' => ['привет, друг', 'привет друг', 'здравствуй, друг', 'здравствуй друг', 'здравствуйте, друг'],
            'vladikish' => ['mira, vladi', 'mira vladi'],
            'en' => ['hello, friend', 'hello friend', 'hi, friend', 'hi friend'],
            'it' => ['ciao, amico', 'ciao amico']
        ],
        [
            'ru' => ['добрый день', 'хорошего дня', 'прекрасный день', 'доброго дня'],
            'vladikish' => ['zora bonu', 'zora vanti'],
            'en' => ['good day', 'good afternoon', 'have a nice day'],
            'it' => ['buon giorno', 'buongiorno', 'buona giornata']
        ],
        [
            'ru' => ['доброе утро', 'с добрым утром'],
            'vladikish' => ['mane bonu', 'zora mira'],
            'en' => ['good morning'],
            'it' => ['buongiorno', 'buona mattina']
        ],
        [
            'ru' => ['добрый вечер'],
            'vladikish' => ['vesper bonu'],
            'en' => ['good evening'],
            'it' => ['buonasera', 'buona sera']
        ],
        [
            'ru' => ['спокойной ночи', 'доброй ночи'],
            'vladikish' => ['nox mira', 'nox bonu'],
            'en' => ['good night', 'sleep well'],
            'it' => ['buonanotte', 'buona notte']
        ],
        [
            'ru' => ['как дела', 'как ты', 'как ваши дела', 'как поживаешь'],
            'vladikish' => ['kvan tu', 'kvan tu est', 'kvan vos'],
            'en' => ['how are you', 'how is it going', 'how do you do'],
            'it' => ['come stai', 'come va']
        ],
        [
            'ru' => ['я люблю тебя', 'я тебя люблю'],
            'vladikish' => ['me toro ti', 'me toro tu'],
            'en' => ['i love you'],
            'it' => ['ti amo', 'io ti amo']
        ],
        [
            'ru' => ['спасибо большое', 'огромное спасибо', 'большое спасибо', 'сердечное спасибо'],
            'vladikish' => ['danko valde', 'danko magnu', 'dankon plena'],
            'en' => ['thank you very much', 'thanks a lot', 'full thanks'],
            'it' => ['grazie mille', 'molte grazie']
        ],
        [
            'ru' => ['до свидания', 'до встречи', 'пока', 'до скорого'],
            'vladikish' => ['vada mira', 'vada', 'gis la revido'],
            'en' => ['goodbye', 'see you', 'bye', 'see you soon'],
            'it' => ['arrivederci', 'ciao', 'a presto']
        ],
        [
            'ru' => ['пожалуйста', 'не за что'],
            'vladikish' => ['plaso', 'de nada'],
            'en' => ['you are welcome', 'please'],
            'it' => ['prego', 'per favore']
        ],
        [
            'ru' => ['шиба — хорошая собака', 'шиба хорошая собака', 'шиба это хорошая собака'],
            'vladikish' => ['shiba barka bonu est', 'shiba barka bonu'],
            'en' => ['shiba is a good dog', 'shiba is good dog'],
            'it' => ['shiba è un buon cane']
        ],
        [
            'ru' => ['солнце светит в небе', 'солнце светит на небе'],
            'vladikish' => ['zora velo in aero'],
            'en' => ['the sun shines in the sky', 'sun shines in the sky'],
            'it' => ['il sole splende nel cielo']
        ],
        [
            'ru' => ['я учу язык владикиш', 'я изучаю язык владикиш', 'я учу владикиш', 'я учу шибалинго'],
            'vladikish' => ['me disce lingo vladikish', 'me disce vladikish'],
            'en' => ['i learn vladikish language', 'i study vladikish', 'i learn shibalingo'],
            'it' => ['imparo la lingua vladikish', 'studio vladikish', 'imparo shibalingo']
        ]
    ];

    /**
     * Built-in base lexicon with morphological roots
     */
    private static array $baseLexicon = [
        // Pronouns
        ['vk' => 'me', 'pos' => 'pronoun', 'ru' => ['я', 'меня', 'мне', 'мной', 'мною'], 'en' => ['i', 'me'], 'it' => ['io', 'me', 'mi'], 'pr' => 'Ме'],
        ['vk' => 'tu', 'pos' => 'pronoun', 'ru' => ['ты', 'тебя', 'тебе', 'тобой', 'тобою'], 'en' => ['you'], 'it' => ['tu', 'te', 'ti'], 'pr' => 'Ту'],
        ['vk' => 'il', 'pos' => 'pronoun', 'ru' => ['он', 'его', 'ему', 'им', 'нем'], 'en' => ['he', 'him'], 'it' => ['lui', 'egli'], 'pr' => 'Иль'],
        ['vk' => 'ela', 'pos' => 'pronoun', 'ru' => ['она', 'её', 'ей', 'ею', 'ней'], 'en' => ['she', 'her'], 'it' => ['lei', 'essa'], 'pr' => 'Э́ла'],
        ['vk' => 'id', 'pos' => 'pronoun', 'ru' => ['оно', 'это'], 'en' => ['it', 'this'], 'it' => ['esso', 'questo'], 'pr' => 'Ид'],
        ['vk' => 'nos', 'pos' => 'pronoun', 'ru' => ['мы', 'нас', 'нам', 'нами'], 'en' => ['we', 'us'], 'it' => ['noi', 'ci'], 'pr' => 'Нос'],
        ['vk' => 'vos', 'pos' => 'pronoun', 'ru' => ['вы', 'вас', 'вам', 'вами'], 'en' => ['you', 'you all'], 'it' => ['voi', 'vi'], 'pr' => 'Вос'],
        ['vk' => 'los', 'pos' => 'pronoun', 'ru' => ['они', 'их', 'им', 'ими', 'них'], 'en' => ['they', 'them'], 'it' => ['loro', 'essi'], 'pr' => 'Лос'],
        ['vk' => 'ce', 'pos' => 'pronoun', 'ru' => ['это', 'этот', 'эта', 'эти', 'этого'], 'en' => ['this', 'these'], 'it' => ['questo', 'questa', 'questi'], 'pr' => 'Че'],
        ['vk' => 'te', 'pos' => 'pronoun', 'ru' => ['то', 'тот', 'та', 'те', 'того'], 'en' => ['that', 'those'], 'it' => ['quello', 'quella', 'quelli'], 'pr' => 'Те'],
        ['vk' => 'mi', 'pos' => 'possessive', 'ru' => ['мой', 'моя', 'моё', 'мои', 'моего', 'моему', 'моей', 'моих', 'моим'], 'en' => ['my', 'mine'], 'it' => ['mio', 'mia', 'miei', 'mie'], 'pr' => 'Ми'],
        ['vk' => 'mea', 'pos' => 'possessive', 'ru' => ['мой', 'моя', 'моё', 'мои'], 'en' => ['my', 'mine'], 'it' => ['mio', 'mia'], 'pr' => 'Ме́а'],
        ['vk' => 'ti', 'pos' => 'possessive', 'ru' => ['твой', 'твоя', 'твоё', 'твои', 'твоего', 'твоей', 'твоих', 'твоим'], 'en' => ['your', 'yours'], 'it' => ['tuo', 'tua', 'tuoi', 'tue'], 'pr' => 'Ти'],
        ['vk' => 'tua', 'pos' => 'possessive', 'ru' => ['твой', 'твоя', 'твоё', 'твои'], 'en' => ['your', 'yours'], 'it' => ['tuo', 'tua'], 'pr' => 'Ту́а'],
        ['vk' => 'nosi', 'pos' => 'possessive', 'ru' => ['наш', 'наша', 'наше', 'наши', 'нашего', 'нашей', 'наших', 'нашим'], 'en' => ['our', 'ours'], 'it' => ['nostro', 'nostra', 'nostri'], 'pr' => 'Но́си'],
        ['vk' => 'nosa', 'pos' => 'possessive', 'ru' => ['наш', 'наша', 'наше', 'наши'], 'en' => ['our', 'ours'], 'it' => ['nostro', 'nostra'], 'pr' => 'Но́са'],
        ['vk' => 'vosi', 'pos' => 'possessive', 'ru' => ['ваш', 'ваша', 'ваше', 'ваши', 'вашего', 'вашей', 'ваших', 'вашим'], 'en' => ['your', 'yours'], 'it' => ['vostro', 'vostra', 'vostri'], 'pr' => 'Во́си'],
        ['vk' => 'vosa', 'pos' => 'possessive', 'ru' => ['ваш', 'ваша', 'ваше', 'ваши'], 'en' => ['your', 'yours'], 'it' => ['vostro', 'vostra'], 'pr' => 'Во́са'],
        ['vk' => 'lori', 'pos' => 'possessive', 'ru' => ['их'], 'en' => ['their', 'theirs'], 'it' => ['loro'], 'pr' => 'Ло́ри'],

        // Copula & Auxiliary
        ['vk' => 'est', 'pos' => 'verb', 'ru' => ['быть', 'есть', 'является', 'находится'], 'en' => ['is', 'am', 'are', 'be'], 'it' => ['è', 'sono', 'essere', 'sta'], 'pr' => 'Эст'],
        ['vk' => 'sun', 'pos' => 'verb', 'ru' => ['суть', 'являются', 'есть'], 'en' => ['are'], 'it' => ['sono', 'stanno'], 'pr' => 'Сун'],
        ['vk' => 'esti', 'pos' => 'verb', 'ru' => ['был', 'была', 'было', 'были'], 'en' => ['was', 'were', 'been'], 'it' => ['era', 'erano', 'stato', 'stata'], 'pr' => 'Э́сти'],
        ['vk' => 'estra', 'pos' => 'verb', 'ru' => ['будет', 'будут', 'буду'], 'en' => ['will be'], 'it' => ['sarà', 'saranno'], 'pr' => 'Э́стра'],

        // Particles & Prepositions
        ['vk' => 'no', 'pos' => 'particle', 'ru' => ['не', 'нет'], 'en' => ['not', 'no', "don't"], 'it' => ['non', 'no'], 'pr' => 'Но'],
        ['vk' => 'non', 'pos' => 'particle', 'ru' => ['не', 'нет'], 'en' => ['not', 'no'], 'it' => ['non', 'no'], 'pr' => 'Нон'],
        ['vk' => 'an', 'pos' => 'particle', 'ru' => ['ли', 'разве', 'неужели'], 'en' => ['do', 'does', 'whether'], 'it' => ['forse', 'se'], 'pr' => 'Ан'],
        ['vk' => 'de', 'pos' => 'preposition', 'ru' => ['от', 'из', 'о', 'родительный падеж'], 'en' => ['of', 'from'], 'it' => ['di', 'da'], 'pr' => 'Де'],
        ['vk' => 'et', 'pos' => 'conjunction', 'ru' => ['и', 'да'], 'en' => ['and'], 'it' => ['e', 'ed'], 'pr' => 'Эт'],
        ['vk' => 'aut', 'pos' => 'conjunction', 'ru' => ['или', 'либо'], 'en' => ['or'], 'it' => ['o', 'oppure'], 'pr' => 'Аут'],
        ['vk' => 'ma', 'pos' => 'conjunction', 'ru' => ['но', 'а', 'однако'], 'en' => ['but', 'however'], 'it' => ['ma', 'però'], 'pr' => 'Ма'],
        ['vk' => 'in', 'pos' => 'preposition', 'ru' => ['в', 'внутри', 'во'], 'en' => ['in', 'inside', 'into'], 'it' => ['in', 'dentro', 'nel', 'nella'], 'pr' => 'Ин'],
        ['vk' => 'su', 'pos' => 'preposition', 'ru' => ['на', 'сверху'], 'en' => ['on', 'upon'], 'it' => ['su', 'sopra', 'sul', 'sulla'], 'pr' => 'Су'],
        ['vk' => 'kun', 'pos' => 'preposition', 'ru' => ['с', 'со', 'вместе с'], 'en' => ['with', 'together with'], 'it' => ['con', 'insieme a'], 'pr' => 'Кун'],
        ['vk' => 'ex', 'pos' => 'preposition', 'ru' => ['из', 'от', 'с'], 'en' => ['from', 'out of'], 'it' => ['da', 'di', 'dal', 'dalla'], 'pr' => 'Экс'],
        ['vk' => 'ad', 'pos' => 'preposition', 'ru' => ['к', 'в', 'до'], 'en' => ['to', 'towards'], 'it' => ['a', 'verso', 'al', 'alla'], 'pr' => 'Ад'],
        ['vk' => 'por', 'pos' => 'preposition', 'ru' => ['для', 'ради', 'за'], 'en' => ['for'], 'it' => ['per'], 'pr' => 'Пор'],
        ['vk' => 'sen', 'pos' => 'preposition', 'ru' => ['без'], 'en' => ['without'], 'it' => ['senza'], 'pr' => 'Сен'],
        ['vk' => 'valde', 'pos' => 'adverb', 'ru' => ['очень', 'весьма', 'сильно', 'много'], 'en' => ['very', 'greatly', 'much'], 'it' => ['molto', 'assai'], 'pr' => 'Ва́льде'],
        ['vk' => 'hic', 'pos' => 'adverb', 'ru' => ['здесь', 'тут'], 'en' => ['here'], 'it' => ['qui', 'qua'], 'pr' => 'Хик'],
        ['vk' => 'ibi', 'pos' => 'adverb', 'ru' => ['там', 'туда'], 'en' => ['there'], 'it' => ['lì', 'là'], 'pr' => 'И́би'],
        ['vk' => 'kvi', 'pos' => 'pronoun', 'ru' => ['кто'], 'en' => ['who'], 'it' => ['chi'], 'pr' => 'Кви'],
        ['vk' => 'kvo', 'pos' => 'pronoun', 'ru' => ['что'], 'en' => ['what'], 'it' => ['che', 'cosa'], 'pr' => 'Кво'],
        ['vk' => 'kvan', 'pos' => 'adverb', 'ru' => ['как', 'каким образом'], 'en' => ['how'], 'it' => ['come'], 'pr' => 'Кван'],
        ['vk' => 'purkvo', 'pos' => 'adverb', 'ru' => ['почему', 'зачем'], 'en' => ['why'], 'it' => ['perché'], 'pr' => 'Пуркво́'],
        ['vk' => 'ubi', 'pos' => 'adverb', 'ru' => ['где', 'куда'], 'en' => ['where'], 'it' => ['dove'], 'pr' => 'У́би'],
        ['vk' => 'ya', 'pos' => 'particle', 'ru' => ['да', 'так'], 'en' => ['yes', 'yeah'], 'it' => ['sì'], 'pr' => 'Я'],

        // Verbs (Prioritized before nouns for verbal inflections)
        ['vk' => 'Toro', 'pos' => 'verb', 'ru' => ['любить', 'ценить', 'обожать', 'полюбить'], 'en' => ['love', 'appreciate'], 'it' => ['amare'], 'pr' => 'То́ро', 'past_ru' => 'полюбил', 'past_en' => 'loved', 'past_it' => 'ha amato', 'imp_ru' => 'Люби!', 'imp_en' => 'Love!', 'imp_it' => 'Ama!'],
        ['vk' => 'Sabi', 'pos' => 'verb', 'ru' => ['знать', 'понимать', 'ведать'], 'en' => ['know', 'understand'], 'it' => ['sapere', 'conoscere'], 'pr' => 'Са́би', 'past_ru' => 'знал', 'past_en' => 'knew', 'past_it' => 'sapeva', 'imp_ru' => 'Знай!', 'imp_en' => 'Know!', 'imp_it' => 'Sappi!'],
        ['vk' => 'Sape', 'pos' => 'verb', 'ru' => ['знать', 'понимать'], 'en' => ['know', 'understand'], 'it' => ['sapere'], 'pr' => 'Са́пе', 'past_ru' => 'знал', 'past_en' => 'knew', 'past_it' => 'sapeva'],
        ['vk' => 'Veni', 'pos' => 'verb', 'ru' => ['приходить', 'идти', 'прибывать', 'прийти', 'приедет'], 'en' => ['come', 'arrive'], 'it' => ['venire', 'arrivare'], 'pr' => 'Ве́ни', 'past_ru' => 'пришел', 'past_en' => 'came', 'past_it' => 'è arrivato', 'imp_ru' => 'Приходи!', 'imp_en' => 'Come!', 'imp_it' => 'Vieni!'],
        ['vk' => 'Koma', 'pos' => 'verb', 'ru' => ['кушать', 'есть', 'питаться'], 'en' => ['eat', 'dine'], 'it' => ['mangiare'], 'pr' => 'Ко́ма', 'past_ru' => 'поел', 'past_en' => 'ate', 'past_it' => 'ha mangiato', 'imp_ru' => 'Кушай!', 'imp_en' => 'Eat!', 'imp_it' => 'Mangia!'],
        ['vk' => 'Helpi', 'pos' => 'verb', 'ru' => ['помогать', 'выручать', 'спасать', 'помочь'], 'en' => ['help', 'assist'], 'it' => ['aiutare'], 'pr' => 'Хе́лпи', 'past_ru' => 'помог', 'past_en' => 'helped', 'past_it' => 'ha aiutato', 'imp_ru' => 'Помоги!', 'imp_en' => 'Help!', 'imp_it' => 'Aiuta!'],
        ['vk' => 'Kompreni', 'pos' => 'verb', 'ru' => ['понимать', 'осознавать', 'понять'], 'en' => ['understand', 'comprehend'], 'it' => ['comprendere', 'capire'], 'pr' => 'Компре́ни', 'past_ru' => 'понял', 'past_en' => 'understood', 'past_it' => 'ha capito', 'imp_ru' => 'Пойми!', 'imp_en' => 'Understand!', 'imp_it' => 'Capisci!'],
        ['vk' => 'Velo', 'pos' => 'verb', 'ru' => ['сиять', 'светить', 'блестеть', 'светится', 'сияет'], 'en' => ['shine', 'glow'], 'it' => ['splendere', 'brillare'], 'pr' => 'Ве́ло', 'past_ru' => 'сиял', 'past_en' => 'shined', 'past_it' => 'splendeva'],
        ['vk' => 'Disce', 'pos' => 'verb', 'ru' => ['учить', 'изучать', 'учиться', 'выучить'], 'en' => ['learn', 'study'], 'it' => ['imparare', 'studiare'], 'pr' => 'Ди́сче', 'past_ru' => 'выучил', 'past_en' => 'learned', 'past_it' => 'ha imparato', 'imp_ru' => 'Учись!', 'imp_en' => 'Learn!', 'imp_it' => 'Impara!'],
        ['vk' => 'Vide', 'pos' => 'verb', 'ru' => ['видеть', 'смотреть', 'глядеть', 'посмотреть', 'увидеть'], 'en' => ['see', 'look', 'watch'], 'it' => ['vedere', 'guardare'], 'pr' => 'Ви́де', 'past_ru' => 'видел', 'past_en' => 'saw', 'past_it' => 'ha visto', 'imp_ru' => 'Смотри!', 'imp_en' => 'Look!', 'imp_it' => 'Guarda!'],
        ['vk' => 'Audi', 'pos' => 'verb', 'ru' => ['слышать', 'слушать', 'услышать', 'послушать'], 'en' => ['hear', 'listen'], 'it' => ['sentire', 'ascoltare'], 'pr' => 'А́уди', 'past_ru' => 'услышал', 'past_en' => 'heard', 'past_it' => 'ha sentito', 'imp_ru' => 'Слушай!', 'imp_en' => 'Listen!', 'imp_it' => 'Ascolta!'],
        ['vk' => 'Vade', 'pos' => 'verb', 'ru' => ['идти', 'ехать', 'ступать', 'пойти'], 'en' => ['go', 'walk'], 'it' => ['andare', 'camminare'], 'pr' => 'Ва́де', 'past_ru' => 'пошел', 'past_en' => 'went', 'past_it' => 'è andato', 'imp_ru' => 'Иди!', 'imp_en' => 'Go!', 'imp_it' => 'Va!'],
        ['vk' => 'Kure', 'pos' => 'verb', 'ru' => ['бежать', 'мчаться', 'бегут', 'бежит'], 'en' => ['run'], 'it' => ['correre'], 'pr' => 'Ку́ре', 'past_ru' => 'побежал', 'past_en' => 'ran', 'past_it' => 'ha corso', 'imp_ru' => 'Беги!', 'imp_en' => 'Run!', 'imp_it' => 'Corri!'],
        ['vk' => 'Vive', 'pos' => 'verb', 'ru' => ['жить', 'проживать'], 'en' => ['live'], 'it' => ['vivere'], 'pr' => 'Ви́ве', 'past_ru' => 'жил', 'past_en' => 'lived', 'past_it' => 'ha vissuto'],
        ['vk' => 'Face', 'pos' => 'verb', 'ru' => ['делать', 'создавать', 'творить', 'сделать'], 'en' => ['do', 'make', 'create'], 'it' => ['fare', 'creare'], 'pr' => 'Фа́че', 'past_ru' => 'сделал', 'past_en' => 'did', 'past_it' => 'ha fatto', 'imp_ru' => 'Делай!', 'imp_en' => 'Do!', 'imp_it' => 'Fai!'],
        ['vk' => 'Vole', 'pos' => 'verb', 'ru' => ['хотеть', 'желать'], 'en' => ['want', 'wish'], 'it' => ['volere', 'desiderare'], 'pr' => 'Во́ле', 'past_ru' => 'хотел', 'past_en' => 'wanted', 'past_it' => 'ha voluto'],
        ['vk' => 'Pote', 'pos' => 'verb', 'ru' => ['мочь', 'быть в силах'], 'en' => ['can', 'able to'], 'it' => ['potere'], 'pr' => 'По́те', 'past_ru' => 'мог', 'past_en' => 'could', 'past_it' => 'ha potuto'],
        ['vk' => 'Pense', 'pos' => 'verb', 'ru' => ['думать', 'мыслить', 'полагать'], 'en' => ['think'], 'it' => ['pensare'], 'pr' => 'Пе́нсе', 'past_ru' => 'думал', 'past_en' => 'thought', 'past_it' => 'ha pensato'],
        ['vk' => 'Dorme', 'pos' => 'verb', 'ru' => ['спать', 'почивать'], 'en' => ['sleep'], 'it' => ['dormire'], 'pr' => 'До́рме', 'past_ru' => 'спал', 'past_en' => 'slept', 'past_it' => 'ha dormito', 'imp_ru' => 'Спи!', 'imp_en' => 'Sleep!', 'imp_it' => 'Dormi!'],
        ['vk' => 'Mandu', 'pos' => 'verb', 'ru' => ['кушать', 'есть'], 'en' => ['eat'], 'it' => ['mangiare'], 'pr' => 'Ма́нду', 'past_ru' => 'поел', 'past_en' => 'ate', 'past_it' => 'ha mangiato'],
        ['vk' => 'Bibe', 'pos' => 'verb', 'ru' => ['пить', 'выпить'], 'en' => ['drink'], 'it' => ['bere'], 'pr' => 'Би́бе', 'past_ru' => 'выпил', 'past_en' => 'drank', 'past_it' => 'ha bevuto', 'imp_ru' => 'Пей!', 'imp_en' => 'Drink!', 'imp_it' => 'Bevi!'],
        ['vk' => 'Lege', 'pos' => 'verb', 'ru' => ['читать', 'прочитать'], 'en' => ['read'], 'it' => ['leggere'], 'pr' => 'Ле́ге', 'past_ru' => 'прочитал', 'past_en' => 'read', 'past_it' => 'ha letto', 'imp_ru' => 'Читай!', 'imp_en' => 'Read!', 'imp_it' => 'Leggi!'],
        ['vk' => 'Skribe', 'pos' => 'verb', 'ru' => ['писать', 'написать'], 'en' => ['write'], 'it' => ['scrivere'], 'pr' => 'Скри́бе', 'past_ru' => 'написал', 'past_en' => 'wrote', 'past_it' => 'ha scritto', 'imp_ru' => 'Пиши!', 'imp_en' => 'Write!', 'imp_it' => 'Scrivi!'],

        // Nouns
        ['vk' => 'Barka', 'pos' => 'noun', 'ru' => ['собака', 'пес', 'верность', 'щенок'], 'en' => ['dog', 'loyalty', 'pup', 'hound'], 'it' => ['cane', 'lealtà'], 'pr' => 'Ба́рка', 'past' => '', 'pl_ru' => 'собаки', 'pl_en' => 'dogs', 'pl_it' => 'cani', 'dim_ru' => 'собачка', 'dim_en' => 'puppy', 'dim_it' => 'cagnolino'],
        ['vk' => 'Kaelo', 'pos' => 'noun', 'ru' => ['звезда', 'светило'], 'en' => ['star', 'luminary'], 'it' => ['stella', 'astro'], 'pr' => 'Ка́эло', 'pl_ru' => 'звёзды', 'pl_en' => 'stars', 'pl_it' => 'stelle', 'dim_it' => 'stellina'],
        ['vk' => 'Aero', 'pos' => 'noun', 'ru' => ['небо', 'полет', 'воздух', 'небеса'], 'en' => ['sky', 'flight', 'air'], 'it' => ['cielo', 'volo', 'aria'], 'pr' => 'А́эро', 'pl_ru' => 'небеса', 'pl_en' => 'skies'],
        ['vk' => 'Vladi', 'pos' => 'noun', 'ru' => ['друг', 'правитель', 'товарищ', 'приятель', 'влади'], 'en' => ['friend', 'ruler', 'companion'], 'it' => ['amico', 'sovrano'], 'pr' => 'Вла́ди', 'pl_ru' => 'друзья', 'pl_en' => 'friends', 'pl_it' => 'amici'],
        ['vk' => 'Shiba', 'pos' => 'noun', 'ru' => ['шиба', 'сиба', 'шиба-ину', 'сиба-ину', 'шибы'], 'en' => ['shiba', 'shiba inu'], 'it' => ['shiba', 'shiba inu'], 'pr' => 'Ши́ба', 'pl_ru' => 'шибы'],
        ['vk' => 'Zora', 'pos' => 'noun', 'ru' => ['день', 'солнце', 'рассвет', 'утро'], 'en' => ['day', 'sun', 'dawn'], 'it' => ['giorno', 'sole', 'alba'], 'pr' => 'Зо́ра', 'pl_ru' => 'дни', 'pl_en' => 'days', 'pl_it' => 'giorni', 'dim_ru' => 'денёк', 'dim_en' => 'nice day', 'dim_it' => 'solicello'],
        ['vk' => 'Nox', 'pos' => 'noun', 'ru' => ['ночь', 'сон', 'тьма'], 'en' => ['night', 'sleep', 'darkness'], 'it' => ['notte', 'sonno'], 'pr' => 'Нокс', 'pl_ru' => 'ночи', 'pl_en' => 'nights', 'pl_it' => 'notti'],
        ['vk' => 'Korno', 'pos' => 'noun', 'ru' => ['сердце', 'душа', 'чувство'], 'en' => ['heart', 'soul', 'feeling'], 'it' => ['cuore', 'anima'], 'pr' => 'Ко́рно', 'pl_ru' => 'сердца', 'pl_en' => 'hearts', 'pl_it' => 'cuori'],
        ['vk' => 'Lingo', 'pos' => 'noun', 'ru' => ['слово', 'язык', 'речь'], 'en' => ['word', 'language', 'speech'], 'it' => ['parola', 'lingua'], 'pr' => 'Ли́нго', 'pl_ru' => 'слова', 'pl_en' => 'words', 'pl_it' => 'parole'],
        ['vk' => 'Homo', 'pos' => 'noun', 'ru' => ['человек', 'персона', 'люди'], 'en' => ['human', 'person', 'people', 'man'], 'it' => ['uomo', 'persona', 'gente'], 'pr' => 'Хо́мо', 'pl_ru' => 'люди', 'pl_en' => 'people', 'pl_it' => 'uomini'],
        ['vk' => 'Mundi', 'pos' => 'noun', 'ru' => ['мир', 'вселенная', 'земля'], 'en' => ['world', 'universe', 'earth'], 'it' => ['mondo', 'universo'], 'pr' => 'Му́нди', 'pl_ru' => 'миры', 'pl_en' => 'worlds', 'pl_it' => 'mondi'],
        ['vk' => 'Luna', 'pos' => 'noun', 'ru' => ['луна', 'месяц'], 'en' => ['moon'], 'it' => ['luna'], 'pr' => 'Лу́на'],
        ['vk' => 'Stella', 'pos' => 'noun', 'ru' => ['звезда', 'светило'], 'en' => ['star', 'luminary'], 'it' => ['stella', 'astro'], 'pr' => 'Сте́лла', 'pl_ru' => 'звёзды', 'pl_en' => 'stars', 'pl_it' => 'stelle', 'dim_ru' => 'звёздочка', 'dim_it' => 'stellina'],
        ['vk' => 'Libro', 'pos' => 'noun', 'ru' => ['книга', 'учебник', 'свиток'], 'en' => ['book', 'textbook'], 'it' => ['libro', 'manuale'], 'pr' => 'Ли́бро', 'pl_ru' => 'книги', 'pl_en' => 'books', 'pl_it' => 'libri'],
        ['vk' => 'Domo', 'pos' => 'noun', 'ru' => ['дом', 'жилище', 'здание'], 'en' => ['house', 'home', 'dwelling'], 'it' => ['casa', 'abitazione'], 'pr' => 'До́мо', 'pl_ru' => 'дома', 'pl_en' => 'houses', 'pl_it' => 'case', 'dim_ru' => 'домик', 'dim_it' => 'casetta'],
        ['vk' => 'Via', 'pos' => 'noun', 'ru' => ['путь', 'дорога'], 'en' => ['path', 'way', 'road'], 'it' => ['via', 'strada'], 'pr' => 'Ви́а', 'pl_ru' => 'пути', 'pl_en' => 'roads'],
        ['vk' => 'Akva', 'pos' => 'noun', 'ru' => ['вода', 'река', 'влага'], 'en' => ['water', 'river'], 'it' => ['acqua', 'fiume'], 'pr' => 'А́ква'],
        ['vk' => 'Igni', 'pos' => 'noun', 'ru' => ['огонь', 'пламя'], 'en' => ['fire', 'flame'], 'it' => ['fuoco', 'fiamma'], 'pr' => 'И́гни'],
        ['vk' => 'Vita', 'pos' => 'noun', 'ru' => ['жизнь', 'бытие'], 'en' => ['life', 'existence'], 'it' => ['vita'], 'pr' => 'Ви́та'],
        ['vk' => 'Tempu', 'pos' => 'noun', 'ru' => ['время', 'час', 'пора'], 'en' => ['time', 'hour'], 'it' => ['tempo', 'ora'], 'pr' => 'Те́мпу'],
        ['vk' => 'Joya', 'pos' => 'noun', 'ru' => ['радость', 'восторг', 'веселье'], 'en' => ['joy', 'happiness', 'delight'], 'it' => ['gioia', 'felicità'], 'pr' => 'Джо́я'],
        ['vk' => 'Amor', 'pos' => 'noun', 'ru' => ['любовь', 'привязанность'], 'en' => ['love', 'affection'], 'it' => ['amore'], 'pr' => 'Амо́р'],
        ['vk' => 'Kristal', 'pos' => 'noun', 'ru' => ['кристалл', 'алмаз', 'камень'], 'en' => ['crystal', 'gem', 'jewel'], 'it' => ['cristallo', 'gemma'], 'pr' => 'Криста́л', 'pl_ru' => 'кристаллы', 'pl_en' => 'crystals'],

        // Adjectives & Adverbs
        ['vk' => 'Bonu', 'pos' => 'adjective', 'ru' => ['хороший', 'добрый', 'славный'], 'en' => ['good', 'kind', 'nice'], 'it' => ['buono', 'gentile'], 'pr' => 'Бо́ну', 'adv_ru' => 'хорошо', 'adv_en' => 'well', 'adv_it' => 'bene'],
        ['vk' => 'Malu', 'pos' => 'adjective', 'ru' => ['плохой', 'злой', 'недобрый'], 'en' => ['bad', 'evil', 'poor'], 'it' => ['cattivo', 'male'], 'pr' => 'Ма́лу', 'adv_ru' => 'плохо', 'adv_en' => 'badly', 'adv_it' => 'male'],
        ['vk' => 'Vanti', 'pos' => 'adjective', 'ru' => ['счастливый', 'прекрасный', 'красивый', 'радостный'], 'en' => ['happy', 'beautiful', 'wonderful', 'lovely'], 'it' => ['felice', 'bello', 'splendido'], 'pr' => 'Ва́нти', 'adv_ru' => 'прекрасно', 'adv_en' => 'wonderfully', 'adv_it' => 'meravigliosamente'],
        ['vk' => 'Balu', 'pos' => 'adjective', 'ru' => ['красивый', 'прекрасный', 'милый', 'красивые', 'красивому'], 'en' => ['beautiful', 'handsome', 'lovely'], 'it' => ['bello', 'stupendo'], 'pr' => 'Ба́лу', 'adv_ru' => 'красиво', 'adv_en' => 'beautifully', 'adv_it' => 'bellamente'],
        ['vk' => 'Rapidu', 'pos' => 'adjective', 'ru' => ['быстрый', 'скорый', 'резвый'], 'en' => ['fast', 'quick', 'rapid'], 'it' => ['rapido', 'veloce'], 'pr' => 'Рапи́ду', 'adv_ru' => 'быстро', 'adv_en' => 'quickly', 'adv_it' => 'rapidamente'],
        ['vk' => 'Magnu', 'pos' => 'adjective', 'ru' => ['большой', 'великий', 'огромный'], 'en' => ['great', 'big', 'large'], 'it' => ['grande', 'magnifico'], 'pr' => 'Ма́гну'],
        ['vk' => 'Mikru', 'pos' => 'adjective', 'ru' => ['маленький', 'небольшой', 'малый'], 'en' => ['small', 'little', 'tiny'], 'it' => ['piccolo'], 'pr' => 'Ми́кру'],
        ['vk' => 'Novu', 'pos' => 'adjective', 'ru' => ['новый', 'свежий'], 'en' => ['new', 'fresh'], 'it' => ['nuovo'], 'pr' => 'Но́ву'],
        ['vk' => 'Vetu', 'pos' => 'adjective', 'ru' => ['старый', 'древний'], 'en' => ['old', 'ancient'], 'it' => ['vecchio', 'antico'], 'pr' => 'Ве́ту'],
        ['vk' => 'Fideli', 'pos' => 'adjective', 'ru' => ['верный', 'преданный', 'надежный'], 'en' => ['loyal', 'faithful', 'true'], 'it' => ['fedele'], 'pr' => 'Фиде́ли', 'adv_ru' => 'верно'],
        ['vk' => 'Sapu', 'pos' => 'adjective', 'ru' => ['мудрый', 'умный'], 'en' => ['wise', 'smart'], 'it' => ['saggio', 'intelligente'], 'pr' => 'Са́пу', 'adv_ru' => 'мудро'],
        ['vk' => 'Facili', 'pos' => 'adjective', 'ru' => ['легкий', 'простой'], 'en' => ['easy', 'simple'], 'it' => ['facile', 'semplice'], 'pr' => 'Фа́чили', 'adv_ru' => 'легко', 'adv_en' => 'easily', 'adv_it' => 'facilmente'],
        ['vk' => 'Auri', 'pos' => 'adjective', 'ru' => ['золотой', 'драгоценный'], 'en' => ['golden', 'gold'], 'it' => ['d\'oro', 'dorato'], 'pr' => 'А́ури'],

        // Greetings & Etiquette
        ['vk' => 'Mira', 'pos' => 'greeting', 'ru' => ['привет', 'здравствуй', 'здравствуйте', 'мир'], 'en' => ['hello', 'hi', 'peace'], 'it' => ['ciao', 'salve'], 'pr' => 'Ми́ра'],
        ['vk' => 'Danko', 'pos' => 'phrase', 'ru' => ['спасибо', 'благодарю'], 'en' => ['thank you', 'thanks'], 'it' => ['grazie'], 'pr' => 'Да́нко'],
        ['vk' => 'Plaso', 'pos' => 'phrase', 'ru' => ['пожалуйста'], 'en' => ['please', 'welcome'], 'it' => ['prego', 'per favore'], 'pr' => 'Пла́со'],
        ['vk' => 'Vada', 'pos' => 'phrase', 'ru' => ['пока', 'прощай', 'до свидания'], 'en' => ['bye', 'goodbye', 'farewell'], 'it' => ['addio', 'ciao'], 'pr' => 'Ва́да'],

        // Numbers
        ['vk' => 'Un', 'pos' => 'numeral', 'ru' => ['один', '1'], 'en' => ['one', '1'], 'it' => ['uno', '1'], 'pr' => 'Ун'],
        ['vk' => 'Du', 'pos' => 'numeral', 'ru' => ['два', '2'], 'en' => ['two', '2'], 'it' => ['due', '2'], 'pr' => 'Ду'],
        ['vk' => 'Tri', 'pos' => 'numeral', 'ru' => ['три', '3'], 'en' => ['three', '3'], 'it' => ['tre', '3'], 'pr' => 'Три'],
        ['vk' => 'Kvar', 'pos' => 'numeral', 'ru' => ['четыре', '4'], 'en' => ['four', '4'], 'it' => ['quattro', '4'], 'pr' => 'Квар'],
        ['vk' => 'Kvin', 'pos' => 'numeral', 'ru' => ['пять', '5'], 'en' => ['five', '5'], 'it' => ['cinque', '5'], 'pr' => 'Квин'],
        ['vk' => 'Ses', 'pos' => 'numeral', 'ru' => ['шесть', '6'], 'en' => ['six', '6'], 'it' => ['sei', '6'], 'pr' => 'Сес'],
        ['vk' => 'Sep', 'pos' => 'numeral', 'ru' => ['семь', '7'], 'en' => ['seven', '7'], 'it' => ['sette', '7'], 'pr' => 'Сеп'],
        ['vk' => 'Ok', 'pos' => 'numeral', 'ru' => ['восемь', '8'], 'en' => ['eight', '8'], 'it' => ['otto', '8'], 'pr' => 'Ок'],
        ['vk' => 'Nov', 'pos' => 'numeral', 'ru' => ['девять', '9'], 'en' => ['nine', '9'], 'it' => ['nove', '9'], 'pr' => 'Нов'],
        ['vk' => 'Dek', 'pos' => 'numeral', 'ru' => ['десять', '10'], 'en' => ['ten', '10'], 'it' => ['dieci', '10'], 'pr' => 'Дек']
    ];

    /**
     * Normalize language code
     */
    public static function normalizeLang(string $lang): string {
        $l = strtolower(trim($lang));
        if ($l === 'shibalingo' || $l === 'vk' || $l === 'vl' || $l === 'conlang') return 'vladikish';
        if ($l === 'russian' || $l === 'rus') return 'ru';
        if ($l === 'english' || $l === 'eng') return 'en';
        if ($l === 'italian' || $l === 'ita') return 'it';
        return $l;
    }

    /**
     * Clean and split dictionary entry into distinct synonyms (Requirement #3)
     */
    public static function parseDictionaryField(?string $rawField): array {
        if (!$rawField) return [];
        $cleaned = preg_replace('/\s*[\(\[\{].*?[\)\]\}]/u', '', $rawField);
        $parts = preg_split('/[\/,;]+/u', $cleaned);
        $result = [];
        foreach ($parts as $p) {
            $t = trim($p);
            if (str_starts_with(strtolower($t), 'to ')) {
                $t = trim(substr($t, 3));
            }
            if ($t !== '' && !in_array($t, $result, true)) {
                $result[] = $t;
            }
        }
        return $result;
    }

    /**
     * Load dynamic dictionary from database and merge with base lexicon
     */
    public static function getMergedLexicon(): array {
        if (self::$dbDictionary !== null) {
            return self::$dbDictionary;
        }

        $lexicon = self::$baseLexicon;

        $sourceRows = [];

        // 1. Try Loading from Database Table
        try {
            $db = getDb();
            $stmt = $db->query("SELECT * FROM " . tbl('conlang_dictionary'));
            $sourceRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}

        // 2. Load / Merge from expansion pack and full dump files for 100% offline completeness
        $jsonFiles = [
            __DIR__ . '/../../vladikish/vladikish_expansion_pack.json',
            __DIR__ . '/../../vladikish/shibalingo_full_database_dump_2026-08-30_22-02.json'
        ];
        foreach ($jsonFiles as $jf) {
            if (file_exists($jf)) {
                $content = @file_get_contents($jf);
                if ($content) {
                    $dumpData = @json_decode($content, true);
                    $wordsList = $dumpData['conlang_dictionary'] ?? [];
                    if (!empty($wordsList)) {
                        $sourceRows = array_merge($sourceRows, $wordsList);
                    }
                }
            }
        }

        foreach ($sourceRows as $r) {
            $vkWord = trim($r['word'] ?? '');
            $pos = trim($r['part_of_speech'] ?? 'noun');
            $ruTranslations = self::parseDictionaryField($r['translation_ru'] ?? '');
            $enTranslations = self::parseDictionaryField($r['translation_en'] ?? '');
            $itTranslations = self::parseDictionaryField($r['translation_it'] ?? '');
            $pron = trim($r['pronunciation'] ?? $vkWord);

            if (!empty($vkWord) && !empty($ruTranslations)) {
                $found = false;
                foreach ($lexicon as &$entry) {
                    if (strcasecmp($entry['vk'], $vkWord) === 0) {
                        $entry['ru'] = array_values(array_unique(array_merge($entry['ru'], $ruTranslations)));
                        if (!empty($enTranslations)) $entry['en'] = array_values(array_unique(array_merge($entry['en'], $enTranslations)));
                        if (!empty($itTranslations)) $entry['it'] = array_values(array_unique(array_merge($entry['it'], $itTranslations)));
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $lexicon[] = [
                        'vk' => $vkWord,
                        'pos' => $pos,
                        'ru' => $ruTranslations,
                        'en' => !empty($enTranslations) ? $enTranslations : $ruTranslations,
                        'it' => !empty($itTranslations) ? $itTranslations : $ruTranslations,
                        'pr' => $pron,
                        'example' => $r['example_sentence'] ?? ''
                    ];
                }
            }
        }

        self::$dbDictionary = $lexicon;
        return self::$dbDictionary;
    }

    /**
     * Stem and decompose a Vladikish word (Requirement #1)
     */
    public static function decomposeVladikish(string $rawWord): array {
        $w = mb_strtolower(trim($rawWord), 'UTF-8');
        $w = preg_replace('/[^\p{L}\-_]/u', '', $w);

        $features = [
            'original' => $rawWord,
            'clean' => $w,
            'root' => $w,
            'tense' => 'present',
            'is_plural' => false,
            'mood' => 'indicative',
            'form' => 'base'
        ];

        if (mb_strlen($w, 'UTF-8') <= 2) {
            return $features;
        }

        $root = $w;

        // 1. Aspect Prefixes: fin- (perfective), ek- (inchoative)
        if (str_starts_with($root, 'fin-') || str_starts_with($root, 'fin_')) {
            $root = substr($root, 4);
            $features['aspect'] = 'perfective';
        } elseif (str_starts_with($root, 'ek-') || str_starts_with($root, 'ek_')) {
            $root = substr($root, 3);
            $features['aspect'] = 'inchoative';
        }

        // 2. Future Prefix: Vo-, Vo_, Vo
        if (str_starts_with($root, 'vo-') || str_starts_with($root, 'vo_')) {
            $root = substr($root, 3);
            $features['tense'] = 'future';
        } elseif (str_starts_with($root, 'vo') && mb_strlen($root, 'UTF-8') >= 5) {
            $root = substr($root, 2);
            $features['tense'] = 'future';
        }

        // 3. Conditional Mood Suffix: -us
        if (str_ends_with($root, 'us') && mb_strlen($root, 'UTF-8') >= 4) {
            $root = substr($root, 0, -2);
            $features['mood'] = 'conditional';
        }

        // 4. Participles & Gerunds: -anta, -inta, -ata, -ante
        if (str_ends_with($root, 'anta') && mb_strlen($root, 'UTF-8') >= 5) {
            $root = substr($root, 0, -4);
            $features['form'] = 'participle_pres';
        } elseif (str_ends_with($root, 'inta') && mb_strlen($root, 'UTF-8') >= 5) {
            $root = substr($root, 0, -4);
            $features['form'] = 'participle_past';
        } elseif (str_ends_with($root, 'ata') && mb_strlen($root, 'UTF-8') >= 5) {
            $root = substr($root, 0, -3);
            $features['form'] = 'participle_pass';
        } elseif (str_ends_with($root, 'ante') && mb_strlen($root, 'UTF-8') >= 5) {
            $root = substr($root, 0, -4);
            $features['form'] = 'gerund';
        }

        // 5. Past Suffix: -ti, -vati, -iti
        if (str_ends_with($root, 'vati') && mb_strlen($root, 'UTF-8') >= 5) {
            $root = substr($root, 0, -4);
            $features['tense'] = 'past';
        } elseif (str_ends_with($root, 'iti') && mb_strlen($root, 'UTF-8') >= 5) {
            $root = substr($root, 0, -3);
            $features['tense'] = 'past';
        } elseif (str_ends_with($root, 'ti') && mb_strlen($root, 'UTF-8') >= 4) {
            $root = substr($root, 0, -2);
            $features['tense'] = 'past';
        }

        // 6. Diminutive Suffix: -ita, -ito
        if ((str_ends_with($root, 'ita') || str_ends_with($root, 'ito')) && mb_strlen($root, 'UTF-8') >= 5) {
            $root = substr($root, 0, -3);
            $features['form'] = 'diminutive';
            $features['dim_stem'] = $root;
        }

        // 7. Adverb Suffix: -e from adjectives
        if (str_ends_with($root, 'e') && mb_strlen($root, 'UTF-8') >= 4) {
            $candidateRoot = substr($root, 0, -1);
            $features['form'] = 'adverb';
            $features['adverb_stem'] = $candidateRoot;
        }

        // 8. Imperative Suffix: -u
        if (str_ends_with($root, 'u') && mb_strlen($root, 'UTF-8') >= 4) {
            $features['mood'] = 'imperative';
            $features['imperative_stem'] = substr($root, 0, -1);
        }

        // 9. Possessive Pronouns: Mea -> Me, Tua -> Tu, Nosa -> Nos, Vosa -> Vos
        if ($root === 'mea' || $root === 'tua' || $root === 'nosa' || $root === 'vosa') {
            $features['form'] = 'possessive';
            if ($root === 'mea') $root = 'me';
            elseif ($root === 'tua') $root = 'tu';
            elseif ($root === 'nosa') $root = 'nos';
            elseif ($root === 'vosa') $root = 'vos';
        }

        // 10. Plural Suffix: -as, -os, -es, -is, -s
        if (str_ends_with($root, 'as') && mb_strlen($root, 'UTF-8') >= 4) {
            $features['is_plural'] = true;
            $features['plural_stem_a'] = substr($root, 0, -1);
            $root = substr($root, 0, -2);
        } elseif (str_ends_with($root, 'os') && mb_strlen($root, 'UTF-8') >= 4) {
            $features['is_plural'] = true;
            $features['plural_stem_o'] = substr($root, 0, -1);
            $root = substr($root, 0, -2);
        } elseif (str_ends_with($root, 's') && !str_ends_with($root, 'nos') && !str_ends_with($root, 'vos') && !str_ends_with($root, 'los') && mb_strlen($root, 'UTF-8') >= 3) {
            $features['is_plural'] = true;
            $root = substr($root, 0, -1);
        }

        $features['root'] = $root;
        return $features;
    }

    /**
     * Russian Lemmatizer and Morphological Feature Extractor (Requirement #2)
     */
    public static function analyzeRussian(string $rawWord): array {
        $w = mb_strtolower(trim($rawWord), 'UTF-8');
        $w = preg_replace('/[^\p{Cyrillic}\-]/u', '', $w);

        $features = [
            'original' => $rawWord,
            'clean' => $w,
            'lemma' => $w,
            'stem' => $w,
            'tense' => 'present',
            'is_plural' => false,
            'mood' => 'indicative',
            'form' => 'base'
        ];

        if (mb_strlen($w, 'UTF-8') <= 2) {
            return $features;
        }

        // Irregular Russian Verbs mapping to base infinitive
        $irregularRussianVerbs = [
            'пришел' => ['lemma' => 'приходить', 'tense' => 'past'],
            'пришла' => ['lemma' => 'приходить', 'tense' => 'past'],
            'пришли' => ['lemma' => 'приходить', 'tense' => 'past'],
            'придет' => ['lemma' => 'приходить', 'tense' => 'future'],
            'придут' => ['lemma' => 'приходить', 'tense' => 'future'],
            'пошел' => ['lemma' => 'идти', 'tense' => 'past'],
            'пошла' => ['lemma' => 'идти', 'tense' => 'past'],
            'пошли' => ['lemma' => 'идти', 'tense' => 'past'],
            'пойдем' => ['lemma' => 'идти', 'tense' => 'future'],
            'пойдут' => ['lemma' => 'идти', 'tense' => 'future'],
            'увидел' => ['lemma' => 'видеть', 'tense' => 'past'],
            'увидела' => ['lemma' => 'видеть', 'tense' => 'past'],
            'увидели' => ['lemma' => 'видеть', 'tense' => 'past'],
            'услышал' => ['lemma' => 'слышать', 'tense' => 'past'],
            'съел' => ['lemma' => 'есть', 'tense' => 'past'],
            'съела' => ['lemma' => 'есть', 'tense' => 'past'],
            'съели' => ['lemma' => 'есть', 'tense' => 'past'],
            'помог' => ['lemma' => 'помогать', 'tense' => 'past'],
            'помогла' => ['lemma' => 'помогать', 'tense' => 'past'],
            'помогли' => ['lemma' => 'помогать', 'tense' => 'past'],
            'полюбил' => ['lemma' => 'любить', 'tense' => 'past'],
            'полюбила' => ['lemma' => 'любить', 'tense' => 'past'],
            'полюбили' => ['lemma' => 'любить', 'tense' => 'past'],
        ];
        if (isset($irregularRussianVerbs[$w])) {
            $features['lemma'] = $irregularRussianVerbs[$w]['lemma'];
            $features['stem'] = $irregularRussianVerbs[$w]['lemma'];
            $features['tense'] = $irregularRussianVerbs[$w]['tense'];
            return $features;
        }

        // Check Imperative
        if (in_array($w, ['ешь', 'кушай', 'помоги', 'смотри', 'слушай', 'учи', 'учись', 'беги', 'пей', 'читай', 'пиши', 'делай'], true)) {
            $features['mood'] = 'imperative';
        }

        // Check Diminutives
        if (preg_match('/(ичка|ечка|очка|ячка|ёнок|енок|онок|чка|чик|ик|ок|ёк|ек|ушка|ышко)$/u', $w) && !in_array($w, ['человек', 'век', 'урок', 'язык'], true)) {
            $features['form'] = 'diminutive';
        }

        // Check Verb Past Tense
        if (in_array($w, ['шел', 'шла', 'шли', 'пошел', 'пошла', 'пошли', 'полюбил', 'полюбила', 'полюбили', 'помог', 'помогла', 'помогли'], true) || preg_match('/(или|ила|ило|ил|ели|ела|ело|ел|али|ала|ало|ал|яли|яла|ял)$/u', $w)) {
            $features['tense'] = 'past';
        }

        // Check Plural forms
        $pluralEndings = ['ами', 'ями', 'ыми', 'ими', 'ах', 'ях', 'ов', 'ев', 'ей', 'ья', 'ии', 'ые', 'ие', 'ы', 'и'];
        $stopPlural = ['мы', 'вы', 'они', 'их', 'или', 'ели', 'али', 'яли', 'помоги', 'учи', 'сиди', 'иди', 'беги'];
        if (!in_array($w, $stopPlural, true) && ($features['tense'] === 'present') && ($features['mood'] === 'indicative')) {
            if (preg_match('/(ами|ями|ыми|ими|ах|ях|ов|ев|ей|ья|ии|ые|ие|ы|и)$/u', $w)) {
                $features['is_plural'] = true;
            }
        }
        // Zero-ending genitive plurals: собак, звезд, книг, домов, друзей, слов
        if (in_array($w, ['собак', 'звезд', 'звёзд', 'книг', 'домов', 'друзей', 'слов', 'людей', 'кристаллов'], true)) {
            $features['is_plural'] = true;
        }

        // Check Adverb
        if (in_array($w, ['хорошо', 'быстро', 'прекрасно', 'красиво', 'плохо', 'легко', 'мудро', 'верно', 'радостно'], true)) {
            $features['form'] = 'adverb';
        }

        // Stemming with prefix stripping (полюбил -> любил -> люб, собачка -> собак)
        $base = $w;
        if (str_starts_with($base, 'по') && mb_strlen($base, 'UTF-8') >= 5 && !in_array($base, ['помоги', 'помогать', 'поезд', 'пол'], true)) {
            $base = mb_substr($base, 2, null, 'UTF-8');
        }

        // Strip diminutive suffix: собачка -> собак
        if ($features['form'] === 'diminutive') {
            $base = preg_replace('/(очка|ечка|ичка|чка|чик|ик|ок|ек)$/u', '', $base);
            if (str_ends_with($base, 'ч')) {
                $base = mb_substr($base, 0, -1, 'UTF-8') . 'к';
            }
        }

        // Strip inflection endings
        $endings = [
            'ейшего', 'ейшему', 'ейшим', 'ейшем', 'ившего', 'евшего', 'увшего', 'ившем', 'ившая', 'ившее', 'ившими', 'ивших',
            'ивания', 'евания', 'иями', 'ями', 'ами', 'ому', 'ему', 'ого', 'его', 'ыми', 'ими', 'ых', 'их', 'ую', 'юю',
            'ый', 'ий', 'ой', 'ая', 'яя', 'ое', 'ее', 'ые', 'ие', 'ем', 'им', 'ом', 'ей', 'ам', 'ям', 'ах', 'ях',
            'ешь', 'ишь', 'ете', 'ите', 'ут', 'ют', 'ат', 'ят', 'ил', 'ила', 'или', 'ела', 'ели', 'али', 'ала', 'ал', 'ли',
            'ть', 'ти', 'ов', 'ев', 'ы', 'и', 'а', 'я', 'у', 'ю', 'е', 'о'
        ];

        $stopWords = ['в', 'во', 'на', 'с', 'со', 'к', 'ко', 'из', 'от', 'до', 'по', 'о', 'об', 'за', 'у', 'и', 'а', 'но', 'да', 'не', 'ни', 'он', 'мы', 'вы', 'я', 'ты', 'их', 'ее', 'его'];
        if (!in_array($base, $stopWords, true)) {
            foreach ($endings as $e) {
                if (mb_substr($base, -mb_strlen($e, 'UTF-8'), null, 'UTF-8') === $e && mb_strlen($base, 'UTF-8') - mb_strlen($e, 'UTF-8') >= 2) {
                    $base = mb_substr($base, 0, -mb_strlen($e, 'UTF-8'), 'UTF-8');
                    break;
                }
            }
        }

        $features['stem'] = $base;
        return $features;
    }

    /**
     * Comprehensive English Morphological and Semantic Analyzer
     */
    public static function analyzeEnglish(string $word): array {
        $w = strtolower(trim($word));
        $w = preg_replace('/[^a-z\-]/', '', $w);

        $features = [
            'original' => $word,
            'clean' => $w,
            'stem' => $w,
            'tense' => 'present',
            'is_plural' => false,
            'mood' => 'indicative',
            'form' => 'base'
        ];

        if (strlen($w) <= 2) return $features;

        // 1. Imperatives
        if (in_array($w, ['eat', 'help', 'learn', 'study', 'look', 'see', 'listen', 'hear', 'go', 'walk', 'run', 'live', 'do', 'make', 'sleep', 'drink', 'read', 'write'], true)) {
            $features['mood'] = 'imperative';
        }

        // 2. Irregular Past Verbs
        $irregularPast = [
            'loved' => 'love', 'knew' => 'know', 'saw' => 'see', 'looked' => 'look', 'came' => 'come',
            'arrived' => 'arrive', 'ate' => 'eat', 'helped' => 'help', 'understood' => 'understand',
            'shined' => 'shine', 'shone' => 'shine', 'learned' => 'learn', 'studied' => 'study',
            'heard' => 'hear', 'went' => 'go', 'walked' => 'walk', 'ran' => 'run', 'lived' => 'live',
            'did' => 'do', 'made' => 'make', 'wanted' => 'want', 'could' => 'can', 'thought' => 'think',
            'slept' => 'sleep', 'drank' => 'drink', 'read' => 'read', 'wrote' => 'write'
        ];
        if (isset($irregularPast[$w])) {
            $features['tense'] = 'past';
            $features['stem'] = $irregularPast[$w];
            return $features;
        }

        // 3. Regular Past Tense (-ed)
        if (str_ends_with($w, 'ed') && strlen($w) >= 4) {
            $features['tense'] = 'past';
            $cand = substr($w, 0, -2);
            if (str_ends_with($cand, 'i')) $cand = substr($cand, 0, -1) . 'y';
            $features['stem'] = $cand;
            return $features;
        }

        // 4. Diminutives & Offspring nouns
        if (in_array($w, ['puppy', 'puppies', 'doggie', 'doggy'], true)) {
            $features['form'] = 'diminutive';
            $features['stem'] = 'dog';
            if ($w === 'puppies') $features['is_plural'] = true;
            return $features;
        }
        if (in_array($w, ['starlet', 'little star'], true)) {
            $features['form'] = 'diminutive';
            $features['stem'] = 'star';
            return $features;
        }
        if (in_array($w, ['cottage', 'cabin'], true)) {
            $features['form'] = 'diminutive';
            $features['stem'] = 'house';
            return $features;
        }

        // 5. Adverbs (-ly, well, fast)
        if ($w === 'well') {
            $features['form'] = 'adverb';
            $features['stem'] = 'good';
            return $features;
        }
        if ($w === 'badly') {
            $features['form'] = 'adverb';
            $features['stem'] = 'bad';
            return $features;
        }
        if (str_ends_with($w, 'ly') && strlen($w) >= 4) {
            $features['form'] = 'adverb';
            $cand = substr($w, 0, -2);
            if (str_ends_with($cand, 'i')) $cand = substr($cand, 0, -1) . 'y';
            $features['stem'] = $cand;
            return $features;
        }

        // 6. Plural Nouns
        $irregularPlurals = [
            'people' => 'human', 'men' => 'man', 'women' => 'woman', 'children' => 'child',
            'dogs' => 'dog', 'stars' => 'star', 'books' => 'book', 'friends' => 'friend',
            'houses' => 'house', 'words' => 'word', 'crystals' => 'crystal', 'days' => 'day',
            'nights' => 'night', 'hearts' => 'heart', 'roads' => 'road', 'ways' => 'way'
        ];
        if (isset($irregularPlurals[$w])) {
            $features['is_plural'] = true;
            $features['stem'] = $irregularPlurals[$w];
            return $features;
        }

        if (str_ends_with($w, 'ies') && strlen($w) >= 4) {
            $features['is_plural'] = true;
            $features['stem'] = substr($w, 0, -3) . 'y';
        } elseif (str_ends_with($w, 'es') && strlen($w) >= 4 && !in_array($w, ['does', 'goes', 'eyes'], true)) {
            $features['is_plural'] = true;
            $features['stem'] = substr($w, 0, -2);
        } elseif (str_ends_with($w, 's') && !str_ends_with($w, 'ss') && !in_array($w, ['this', 'us', 'is', 'has', 'was', 'yes', 'always'], true) && strlen($w) >= 3) {
            $features['is_plural'] = true;
            $features['stem'] = substr($w, 0, -1);
        }

        return $features;
    }

    /**
     * Comprehensive Italian Morphological and Semantic Analyzer
     */
    public static function analyzeItalian(string $word): array {
        $w = mb_strtolower(trim($word), 'UTF-8');
        $w = preg_replace('/[^a-zàèéìòù\-]/u', '', $w);

        $features = [
            'original' => $word,
            'clean' => $w,
            'stem' => $w,
            'tense' => 'present',
            'is_plural' => false,
            'mood' => 'indicative',
            'form' => 'base'
        ];

        // 1. Italian Present Verb Conjugations -> Infinitives (including 2-letter verbs: so, sa, fa, va)
        $presentConjugations = [
            'so' => 'sapere', 'sai' => 'sapere', 'sa' => 'sapere', 'sappiamo' => 'sapere', 'sapete' => 'sapere', 'sanno' => 'sapere',
            'amo' => 'amare', 'ami' => 'amare', 'ama' => 'amare', 'amiamo' => 'amare', 'amate' => 'amare', 'amano' => 'amare',
            'vengo' => 'venire', 'vieni' => 'venire', 'viene' => 'venire', 'veniamo' => 'venire', 'venite' => 'venire', 'vengono' => 'venire',
            'mangio' => 'mangiare', 'mangi' => 'mangiare', 'mangia' => 'mangiare', 'mangiamo' => 'mangiare', 'mangiate' => 'mangiare', 'mangiano' => 'mangiare',
            'aiuto' => 'aiutare', 'aiuti' => 'aiutare', 'aiuta' => 'aiutare', 'aiutiamo' => 'aiutare', 'aiutate' => 'aiutare', 'aiutano' => 'aiutare',
            'capisco' => 'capire', 'capisci' => 'capire', 'capisce' => 'capire', 'capiamo' => 'capire', 'capite' => 'capire', 'capiscono' => 'capire',
            'vedo' => 'vedere', 'vedi' => 'vedere', 'vede' => 'vedere', 'vediamo' => 'vedere', 'vedete' => 'vedere', 'vedono' => 'vedere',
            'sento' => 'sentire', 'senti' => 'sentire', 'sente' => 'sentire', 'sentiamo' => 'sentire', 'sentite' => 'sentire', 'sentono' => 'sentire',
            'vado' => 'andare', 'vai' => 'andare', 'va' => 'andare', 'andiamo' => 'andare', 'andate' => 'andare', 'vanno' => 'andare',
            'corro' => 'correre', 'corri' => 'correre', 'corre' => 'correre', 'corriamo' => 'correre', 'correte' => 'correre', 'corrono' => 'correre',
            'vivo' => 'vivere', 'vivi' => 'vivere', 'vive' => 'vivere', 'viviamo' => 'vivere', 'vivete' => 'vivere', 'vivono' => 'vivere',
            'faccio' => 'fare', 'fai' => 'fare', 'fa' => 'fare', 'facciamo' => 'fare', 'fate' => 'fare', 'fanno' => 'fare',
            'leggo' => 'leggere', 'leggi' => 'leggere', 'legge' => 'leggere', 'leggiamo' => 'leggere', 'leggete' => 'leggere', 'leggono' => 'leggere',
            'scrivo' => 'scrivere', 'scrivi' => 'scrivere', 'scrive' => 'scrivere', 'scriviamo' => 'scrivere', 'scrivete' => 'scrivere', 'scrivono' => 'scrivere',
            'splende' => 'splendere', 'splendono' => 'splendere', 'brilla' => 'brillare', 'brillano' => 'brillare'
        ];
        if (isset($presentConjugations[$w])) {
            $features['stem'] = $presentConjugations[$w];
            return $features;
        }

        if (mb_strlen($w, 'UTF-8') <= 2) return $features;

        // 2. Enclitics on Imperatives (aiutami -> aiuta, guardami -> guarda, ascoltami -> ascolta)
        if (preg_match('/^(aiuta|guarda|ascolta|impara|corri|leggi|scrivi)(mi|ti|ci|vi|lo|la|li|le)$/u', $w, $encliticMatch)) {
            $w = $encliticMatch[1];
            $features['mood'] = 'imperative';
        }

        // 3. Imperatives
        if (in_array($w, ['mangia', 'aiuta', 'impara', 'studia', 'guarda', 'ascolta', 'corri', 'vivi', 'fai', 'dormi', 'bevi', 'leggi', 'scrivi', 'va'], true)) {
            $features['mood'] = 'imperative';
        }

        // 4. Italian Past Verbs (Passato prossimo, remoto, imperfetto)
        $pastVerbs = [
            'amato' => 'amare', 'amò' => 'amare', 'amava' => 'amare',
            'saputo' => 'sapere', 'seppe' => 'sapere', 'sapeva' => 'sapere',
            'conosciuto' => 'conoscere', 'venuto' => 'venire', 'venne' => 'venire', 'veniva' => 'venire',
            'arrivato' => 'arrivare', 'mangiato' => 'mangiare', 'mangiò' => 'mangiare', 'mangiava' => 'mangiare',
            'aiutato' => 'aiutare', 'aiutò' => 'aiutare', 'aiutava' => 'aiutare',
            'capito' => 'capire', 'comprese' => 'comprendere', 'capiva' => 'capire',
            'splenduto' => 'splendere', 'splese' => 'splendere', 'brillato' => 'brillare',
            'imparato' => 'imparare', 'studiato' => 'studiare',
            'visto' => 'vedere', 'vide' => 'vedere', 'vedeva' => 'vedere', 'guardato' => 'guardare',
            'sentito' => 'sentire', 'ascoltato' => 'ascoltare',
            'andato' => 'andare', 'andò' => 'andare', 'andava' => 'andare', 'camminato' => 'camminare',
            'corso' => 'correre', 'corse' => 'correre', 'correva' => 'correre',
            'vissuto' => 'vivere', 'visse' => 'vivere', 'viveva' => 'vivere',
            'fatto' => 'fare', 'fece' => 'fare', 'faceva' => 'fare',
            'voluto' => 'volere', 'volle' => 'volere', 'voleva' => 'volere',
            'potuto' => 'potere', 'poté' => 'potere', 'poteva' => 'potere',
            'pensato' => 'pensare', 'pensò' => 'pensare', 'pensava' => 'pensare',
            'dormito' => 'dormire', 'dormiva' => 'dormire',
            'bevuto' => 'bere', 'beveva' => 'bere',
            'letto' => 'leggere', 'lesse' => 'leggere', 'leggeva' => 'leggere',
            'scritto' => 'scrivere', 'scrisse' => 'scrivere', 'scriveva' => 'scrivere'
        ];
        if (isset($pastVerbs[$w])) {
            $features['tense'] = 'past';
            $features['stem'] = $pastVerbs[$w];
            return $features;
        }

        // 5. Italian Future Verbs
        $futureVerbs = [
            'amerà' => 'amare', 'amerò' => 'amare', 'ameranno' => 'amare',
            'saprà' => 'sapere', 'saprò' => 'sapere', 'verrà' => 'venire', 'verrò' => 'venire',
            'mangerà' => 'mangiare', 'aiuterà' => 'aiutare', 'capirà' => 'capire',
            'imparerà' => 'imparare', 'vedrà' => 'vedere', 'sentirà' => 'sentire',
            'andrà' => 'andare', 'correrà' => 'correre', 'vivrà' => 'vivere',
            'farà' => 'fare', 'vorrà' => 'volere', 'potrà' => 'potere',
            'penserà' => 'pensare', 'dormirà' => 'dormire', 'berrà' => 'bere',
            'leggerà' => 'leggere', 'scriverà' => 'scrivere'
        ];
        if (isset($futureVerbs[$w])) {
            $features['tense'] = 'future';
            $features['stem'] = $futureVerbs[$w];
            return $features;
        }

        // 6. Italian Diminutives
        $diminutives = [
            'cagnolino' => 'cane', 'cagnetto' => 'cane', 'cagnolina' => 'cane',
            'stellina' => 'stella', 'stelline' => 'stella',
            'casetta' => 'casa', 'casina' => 'casa',
            'solicello' => 'sole', 'libretto' => 'libro'
        ];
        if (isset($diminutives[$w])) {
            $features['form'] = 'diminutive';
            $features['stem'] = $diminutives[$w];
            if (str_ends_with($w, 'e') || str_ends_with($w, 'i')) $features['is_plural'] = true;
            return $features;
        }

        // 7. Italian Adverbs (-mente, bene, male, velocemente)
        if ($w === 'bene') {
            $features['form'] = 'adverb';
            $features['stem'] = 'buono';
            return $features;
        }
        if ($w === 'male') {
            $features['form'] = 'adverb';
            $features['stem'] = 'cattivo';
            return $features;
        }
        if ($w === 'velocemente') {
            $features['form'] = 'adverb';
            $features['stem'] = 'rapido';
            return $features;
        }
        if (str_ends_with($w, 'mente') && strlen($w) >= 6) {
            $features['form'] = 'adverb';
            $cand = substr($w, 0, -5);
            $features['stem'] = $cand;
            return $features;
        }

        // 8. Italian Plural Nouns & Adjectives
        $pluralMap = [
            'cani' => 'cane', 'stelle' => 'stella', 'amici' => 'amico', 'amiche' => 'amico',
            'libri' => 'libro', 'case' => 'casa', 'uomini' => 'uomo', 'parole' => 'parola',
            'cristalli' => 'cristallo', 'giorni' => 'giorno', 'notti' => 'notte',
            'cuori' => 'cuore', 'strade' => 'strada', 'acque' => 'acqua',
            'tempi' => 'tempo', 'gioie' => 'gioia', 'buoni' => 'buono', 'buone' => 'buono',
            'belli' => 'bello', 'belle' => 'bello', 'bei' => 'bello', 'felici' => 'felice', 'grandi' => 'grande',
            'piccoli' => 'piccolo', 'piccole' => 'piccolo', 'nuovi' => 'nuovo', 'nuove' => 'nuovo',
            'vecchi' => 'vecchio', 'vecchie' => 'vecchio', 'fedeli' => 'fedele', 'facili' => 'facile'
        ];
        if (isset($pluralMap[$w])) {
            $features['is_plural'] = true;
            $features['stem'] = $pluralMap[$w];
            return $features;
        }

        // General Italian stemmer
        $endings = ['iamo', 'anno', 'ette', 'este', 'arono', 'irono', 'ano', 'ono', 'ate', 'ete', 'ite', 'are', 'ere', 'ire', 'ato', 'ito', 'uto', 'i', 'e', 'a', 'o'];
        $stopWords = ['in', 'su', 'da', 'di', 'ad', 'un', 'il', 'lo', 'la', 'le', 'li', 'gli', 'io', 'tu', 'lui', 'lei', 'noi', 'voi', 'loro', 'ma', 'ed', 'te', 'me', 'ti', 'mi', 'ci', 'vi', 'se'];
        if (!in_array($w, $stopWords, true)) {
            foreach ($endings as $end) {
                if (str_ends_with($w, $end) && strlen($w) - strlen($end) >= 3) {
                    $features['stem'] = substr($w, 0, -strlen($end));
                    break;
                }
            }
        }

        return $features;
    }

    /**
     * Find token in lexicon using exact match or morphology
     */
    public static function lookupWord(string $rawToken, string $fromLang, string $toLang, array $lexicon): ?array {
        $clean = mb_strtolower(trim($rawToken), 'UTF-8');
        if ($clean === '') return null;

        // 1. Exact match in base lexicon
        foreach ($lexicon as $entry) {
            if ($fromLang === 'vladikish') {
                if (strcasecmp($entry['vk'], $clean) === 0) {
                    return array_merge($entry, ['features' => ['tense' => 'present', 'is_plural' => false]]);
                }
            } else {
                $variants = $entry[$fromLang] ?? [];
                foreach ($variants as $v) {
                    if (strcasecmp(trim($v), $clean) === 0) {
                        return array_merge($entry, ['features' => ['tense' => 'present', 'is_plural' => false]]);
                    }
                }
            }
        }

        // 2. Vladikish Morphological lookup (Vo-toro, Barkas, Toroti, Komu, Bone, Barkita, Saviti)
        if ($fromLang === 'vladikish') {
            $decomp = self::decomposeVladikish($rawToken);
            $root = $decomp['root'];
            $altRoot = str_replace('v', 'b', $root);

            foreach ($lexicon as $entry) {
                $vkLower = mb_strtolower($entry['vk'], 'UTF-8');
                // Direct root match or stem prefix
                if ($vkLower === $root || strcasecmp($entry['vk'], $root) === 0 || $vkLower === $altRoot
                    || (strlen($root) >= 3 && (str_starts_with($vkLower, $root) || str_starts_with($vkLower, $altRoot)))) {
                    return array_merge($entry, ['features' => $decomp]);
                }
                // Root with trailing vowel (e.g. Barka from Barkas, Kaelo from Kaelos)
                if (isset($decomp['plural_stem_a']) && $vkLower === $decomp['plural_stem_a']) {
                    return array_merge($entry, ['features' => $decomp]);
                }
                if (isset($decomp['plural_stem_o']) && $vkLower === $decomp['plural_stem_o']) {
                    return array_merge($entry, ['features' => $decomp]);
                }
                // Diminutive root (Barkita -> Barka, Zorita -> Zora, Stellita -> Stella)
                if (isset($decomp['dim_stem']) && (str_starts_with($vkLower, $decomp['dim_stem']) || $vkLower === $decomp['dim_stem'] . 'a' || $vkLower === $decomp['dim_stem'] . 'o')) {
                    return array_merge($entry, ['features' => $decomp]);
                }
                // Adverb root (Bone -> Bonu, Rapide -> Rapidu)
                if (isset($decomp['adverb_stem']) && (str_starts_with($vkLower, $decomp['adverb_stem']) || $vkLower === $decomp['adverb_stem'] . 'u' || $vkLower === $decomp['adverb_stem'] . 'i')) {
                    return array_merge($entry, ['features' => $decomp]);
                }
                // Imperative root (Komu -> Koma, Helpu -> Helpi, Discu -> Disce)
                if (isset($decomp['imperative_stem']) && (str_starts_with($vkLower, $decomp['imperative_stem']) || $vkLower === $decomp['imperative_stem'] . 'a' || $vkLower === $decomp['imperative_stem'] . 'i' || $vkLower === $decomp['imperative_stem'] . 'e')) {
                    return array_merge($entry, ['features' => $decomp]);
                }
            }
        }

        // 3. Russian Morphological lookup
        if ($fromLang === 'ru') {
            $analysis = self::analyzeRussian($rawToken);
            $stem = $analysis['stem'] ?? '';

            if ($stem !== '' && mb_strlen($stem, 'UTF-8') >= 2) {
                // If tense is past/future, check verbs first
                if (!empty($analysis['tense']) && $analysis['tense'] !== 'present') {
                    foreach ($lexicon as $entry) {
                        if (($entry['pos'] ?? '') === 'verb') {
                            $variants = $entry['ru'] ?? [];
                            foreach ($variants as $v) {
                                $vAnalysis = self::analyzeRussian($v);
                                $vStem = $vAnalysis['stem'] ?? '';
                                if ($vStem !== '' && (strcasecmp($vStem, $stem) === 0 || str_starts_with($vStem, $stem) || str_starts_with($stem, $vStem))) {
                                    return array_merge($entry, ['features' => $analysis]);
                                }
                            }
                        }
                    }
                }

                foreach ($lexicon as $entry) {
                    $variants = $entry['ru'] ?? [];
                    foreach ($variants as $v) {
                        $vAnalysis = self::analyzeRussian($v);
                        $vStem = $vAnalysis['stem'] ?? '';
                        if ($vStem !== '' && (strcasecmp($vStem, $stem) === 0 || (mb_strlen($stem, 'UTF-8') >= 3 && str_starts_with($vStem, $stem)) || (mb_strlen($vStem, 'UTF-8') >= 3 && str_starts_with($stem, $vStem)))) {
                            return array_merge($entry, ['features' => $analysis]);
                        }
                    }
                }
            }
        }

        // 4. English Morphological lookup
        if ($fromLang === 'en') {
            $analysis = self::analyzeEnglish($rawToken);
            $stem = $analysis['stem'] ?? '';

            if ($stem !== '' && strlen($stem) >= 2) {
                // Check exact word variant or stem matching
                foreach ($lexicon as $entry) {
                    $variants = $entry['en'] ?? [];
                    foreach ($variants as $v) {
                        $vAnalysis = self::analyzeEnglish($v);
                        $vStem = $vAnalysis['stem'] ?? '';
                        if ($vStem !== '' && (strcasecmp($vStem, $stem) === 0 || strcasecmp($v, $stem) === 0 || (strlen($stem) >= 3 && str_starts_with($v, $stem)))) {
                            return array_merge($entry, ['features' => $analysis]);
                        }
                    }
                }
            }
        }

        // 5. Italian Morphological lookup
        if ($fromLang === 'it') {
            $analysis = self::analyzeItalian($rawToken);
            $stem = $analysis['stem'] ?? '';

            if ($stem !== '' && strlen($stem) >= 2) {
                // Pass 1: Exact matches on variants or stems
                foreach ($lexicon as $entry) {
                    $variants = $entry['it'] ?? [];
                    foreach ($variants as $v) {
                        $vAnalysis = self::analyzeItalian($v);
                        $vStem = $vAnalysis['stem'] ?? '';
                        if (strcasecmp($v, $rawToken) === 0 || strcasecmp($v, $stem) === 0 || ($vStem !== '' && strcasecmp($vStem, $stem) === 0)) {
                            return array_merge($entry, ['features' => $analysis]);
                        }
                    }
                }

                // Pass 2: Prefix matches with length >= 3
                if (strlen($stem) >= 3) {
                    foreach ($lexicon as $entry) {
                        $variants = $entry['it'] ?? [];
                        foreach ($variants as $v) {
                            $vAnalysis = self::analyzeItalian($v);
                            $vStem = $vAnalysis['stem'] ?? '';
                            if ($vStem !== '' && strlen($vStem) >= 3 && (str_starts_with($vStem, $stem) || str_starts_with($stem, $vStem))) {
                                return array_merge($entry, ['features' => $analysis]);
                            }
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Synthesize target word with appropriate grammatical morphology
     */
    public static function synthesizeTargetWord(array $entry, string $toLang, array $features, string $sourceToken): string {
        $translated = '';

        if ($toLang === 'vladikish') {
            $translated = $entry['vk'];

            // Plural suffix -s / -as / -os
            if (!empty($features['is_plural']) && !str_ends_with($translated, 's')) {
                $translated .= 's';
            }

            // Past tense suffix -ti
            if (!empty($features['tense']) && $features['tense'] === 'past') {
                $translated .= 'ti';
            }
            // Future tense prefix Vo-
            elseif (!empty($features['tense']) && $features['tense'] === 'future') {
                $translated = 'Vo-' . lcfirst($translated);
            }

            // Imperative mood suffix -u
            if (!empty($features['mood']) && $features['mood'] === 'imperative') {
                if (str_ends_with($translated, 'a') || str_ends_with($translated, 'i') || str_ends_with($translated, 'e')) {
                    $translated = substr($translated, 0, -1) . 'u';
                } else {
                    $translated .= 'u';
                }
            }

            // Adverb suffix -e
            if (!empty($features['form']) && $features['form'] === 'adverb') {
                if (str_ends_with($translated, 'u') || str_ends_with($translated, 'i')) {
                    $translated = substr($translated, 0, -1) . 'e';
                } else {
                    $translated .= 'e';
                }
            }

            // Diminutive suffix -ita
            if (!empty($features['form']) && $features['form'] === 'diminutive') {
                if (str_ends_with($translated, 'a') || str_ends_with($translated, 'o')) {
                    $translated = substr($translated, 0, -1) . 'ita';
                } else {
                    $translated .= 'ita';
                }
            }
            // Conditional mood suffix -us
            if (!empty($features['mood']) && $features['mood'] === 'conditional') {
                if (str_ends_with($translated, 'a') || str_ends_with($translated, 'i') || str_ends_with($translated, 'e')) {
                    $translated = substr($translated, 0, -1) . 'us';
                } else {
                    $translated .= 'us';
                }
            }

            // Participles (-anta, -inta, -ata, -ante)
            if (!empty($features['form']) && $features['form'] === 'participle_pres') {
                $translated = (str_ends_with($translated, 'a') || str_ends_with($translated, 'i') || str_ends_with($translated, 'e') ? substr($translated, 0, -1) : $translated) . 'anta';
            } elseif (!empty($features['form']) && $features['form'] === 'participle_past') {
                $translated = (str_ends_with($translated, 'a') || str_ends_with($translated, 'i') || str_ends_with($translated, 'e') ? substr($translated, 0, -1) : $translated) . 'inta';
            } elseif (!empty($features['form']) && $features['form'] === 'participle_pass') {
                $translated = (str_ends_with($translated, 'a') || str_ends_with($translated, 'i') || str_ends_with($translated, 'e') ? substr($translated, 0, -1) : $translated) . 'ata';
            } elseif (!empty($features['form']) && $features['form'] === 'gerund') {
                $translated = (str_ends_with($translated, 'a') || str_ends_with($translated, 'i') || str_ends_with($translated, 'e') ? substr($translated, 0, -1) : $translated) . 'ante';
            }

            // Aspect Prefixes: fin- (perfective), ek- (inchoative)
            if (!empty($features['aspect']) && $features['aspect'] === 'perfective' && !str_starts_with($translated, 'fin-')) {
                $translated = 'fin-' . lcfirst($translated);
            } elseif (!empty($features['aspect']) && $features['aspect'] === 'inchoative' && !str_starts_with($translated, 'ek-')) {
                $translated = 'ek-' . lcfirst($translated);
            }
        } else {
            // Target is Russian / English / Italian
            if (!empty($features['is_plural']) && !empty($entry['pl_' . $toLang])) {
                $translated = $entry['pl_' . $toLang];
            } elseif (!empty($features['is_plural']) && $toLang === 'en') {
                $base = !empty($entry['en']) ? $entry['en'][0] : $entry['vk'];
                $translated = str_ends_with($base, 's') || str_ends_with($base, 'x') || str_ends_with($base, 'ch') || str_ends_with($base, 'sh') ? $base . 'es' : $base . 's';
            } elseif (!empty($features['is_plural']) && $toLang === 'it') {
                $base = !empty($entry['it']) ? $entry['it'][0] : $entry['vk'];
                if (str_ends_with($base, 'o')) $translated = substr($base, 0, -1) . 'i';
                elseif (str_ends_with($base, 'a')) $translated = substr($base, 0, -1) . 'e';
                elseif (str_ends_with($base, 'e')) $translated = substr($base, 0, -1) . 'i';
                else $translated = $base;
            } elseif (!empty($features['tense']) && $features['tense'] === 'past' && !empty($entry['past_' . $toLang])) {
                $translated = $entry['past_' . $toLang];
            } elseif (!empty($features['mood']) && $features['mood'] === 'imperative' && !empty($entry['imp_' . $toLang])) {
                $translated = $entry['imp_' . $toLang];
            } elseif (!empty($features['mood']) && $features['mood'] === 'conditional') {
                if ($toLang === 'ru') {
                    $basePast = !empty($entry['past_ru']) ? $entry['past_ru'] : (!empty($entry['ru']) ? $entry['ru'][0] . 'л' : 'был');
                    $translated = $basePast . ' бы';
                } elseif ($toLang === 'en') {
                    $baseVerb = !empty($entry['en']) ? $entry['en'][0] : $entry['vk'];
                    $translated = 'would ' . $baseVerb;
                } elseif ($toLang === 'it') {
                    $baseVerb = !empty($entry['it']) ? $entry['it'][0] : $entry['vk'];
                    $translated = 'vorrebbe ' . $baseVerb;
                }
            } elseif (!empty($features['form']) && $features['form'] === 'participle_pres') {
                if ($toLang === 'en') $translated = (!empty($entry['en']) ? $entry['en'][0] : $entry['vk']) . 'ing';
                elseif ($toLang === 'ru') $translated = (!empty($entry['ru']) ? $entry['ru'][0] : $entry['vk']) . 'ущий';
                elseif ($toLang === 'it') $translated = (!empty($entry['it']) ? $entry['it'][0] : $entry['vk']) . 'ante';
            } elseif (!empty($features['form']) && $features['form'] === 'participle_pass') {
                if ($toLang === 'en') $translated = (!empty($entry['past_en']) ? $entry['past_en'] : (!empty($entry['en']) ? $entry['en'][0] . 'ed' : 'written'));
                elseif ($toLang === 'ru') $translated = (!empty($entry['ru']) ? $entry['ru'][0] . 'нный' : 'сделанный');
                elseif ($toLang === 'it') $translated = (!empty($entry['past_it']) ? $entry['past_it'] : (!empty($entry['it']) ? $entry['it'][0] . 'to' : 'fatto'));
            } elseif (!empty($features['form']) && $features['form'] === 'adverb' && !empty($entry['adv_' . $toLang])) {
                $translated = $entry['adv_' . $toLang];
            } elseif (!empty($features['form']) && $features['form'] === 'adverb' && $toLang === 'en') {
                $base = !empty($entry['en']) ? $entry['en'][0] : $entry['vk'];
                $translated = (str_ends_with($base, 'e') ? substr($base, 0, -1) : $base) . 'ly';
            } elseif (!empty($features['form']) && $features['form'] === 'adverb' && $toLang === 'it') {
                $base = !empty($entry['it']) ? $entry['it'][0] : $entry['vk'];
                $translated = (str_ends_with($base, 'o') ? substr($base, 0, -1) . 'a' : $base) . 'mente';
            } elseif (!empty($features['form']) && $features['form'] === 'diminutive' && !empty($entry['dim_' . $toLang])) {
                $translated = $entry['dim_' . $toLang];
            } elseif (!empty($features['form']) && $features['form'] === 'diminutive' && $toLang === 'en') {
                $base = !empty($entry['en']) ? $entry['en'][0] : $entry['vk'];
                $translated = 'little ' . $base;
            } elseif (!empty($features['form']) && $features['form'] === 'diminutive' && $toLang === 'it') {
                $base = !empty($entry['it']) ? $entry['it'][0] : $entry['vk'];
                $translated = (str_ends_with($base, 'o') || str_ends_with($base, 'a') || str_ends_with($base, 'e') ? substr($base, 0, -1) : $base) . 'ino';
            } else {
                $variants = $entry[$toLang] ?? [];
                $translated = !empty($variants) ? $variants[0] : $entry['vk'];

                if (!empty($features['tense']) && $features['tense'] === 'future') {
                    if ($toLang === 'ru') $translated = 'будет ' . $translated;
                    elseif ($toLang === 'en') $translated = 'will ' . $translated;
                    elseif ($toLang === 'it') $translated = 'sarà ' . $translated;
                }
            }
        }

        return self::applyCasing($translated, $sourceToken);
    }

    /**
     * Format target word based on casing of source token
     */
    private static function applyCasing(string $targetWord, string $sourceToken): string {
        if ($targetWord === '' || $sourceToken === '') return $targetWord;

        if (mb_strtoupper($sourceToken, 'UTF-8') === $sourceToken && mb_strlen($sourceToken, 'UTF-8') > 1) {
            return mb_strtoupper($targetWord, 'UTF-8');
        }

        $firstChar = mb_substr($sourceToken, 0, 1, 'UTF-8');
        if (mb_strtoupper($firstChar, 'UTF-8') === $firstChar && mb_strtolower($firstChar, 'UTF-8') !== $firstChar) {
            return mb_strtoupper(mb_substr($targetWord, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($targetWord, 1, null, 'UTF-8');
        }

        return $targetWord;
    }

    /**
     * Main translation entry point
     */
    public static function translate(string $text, string $fromLang = 'ru', string $toLang = 'vladikish'): array {
        $text = trim($text);
        $fromLang = self::normalizeLang($fromLang);
        $toLang = self::normalizeLang($toLang);

        if ($text === '') {
            return [
                'success' => true,
                'translated_text' => '',
                'tokens' => [],
                'phrases_matched' => 0,
                'recognized_count' => 0,
                'total_words' => 0
            ];
        }

        $lexicon = self::getMergedLexicon();
        $tokensBreakdown = [];
        $recognizedCount = 0;

        if ($fromLang === $toLang) {
            return [
                'success' => true,
                'translated_text' => $text,
                'tokens' => [],
                'phrases_matched' => 0,
                'recognized_count' => 0,
                'total_words' => count(preg_split('/\s+/u', $text))
            ];
        }

        $workingText = $text;

        // Step 1: Match multi-word phrases with safe placeholder masking
        $phraseMatches = 0;
        $placeholders = [];
        $pCounter = 0;

        foreach (self::$phrases as $phraseEntry) {
            $fromPhrases = $phraseEntry[$fromLang] ?? [];
            $toPhrases = $phraseEntry[$toLang] ?? [];

            if (!empty($fromPhrases) && !empty($toPhrases)) {
                $targetPhrase = $toPhrases[0];
                foreach ($fromPhrases as $srcPhrase) {
                    $pattern = '/\b' . preg_quote($srcPhrase, '/') . '\b/ui';
                    if (preg_match($pattern, $workingText)) {
                        $workingText = preg_replace_callback($pattern, function($m) use ($targetPhrase, &$placeholders, &$pCounter) {
                            $phKey = "___PHRASE_MASK_{$pCounter}___";
                            $placeholders[$phKey] = self::applyCasing($targetPhrase, $m[0]);
                            $pCounter++;
                            return $phKey;
                        }, $workingText);
                        $phraseMatches++;
                    }
                }
            }
        }

        // Step 2: Language-specific syntactic transformations (Future, Negation, Compound Tenses, Genitives)
        // 2a. Russian -> Vladikish: "буду любить" -> "Vo-toro", "не знаю" -> "no Sabi"
        if ($fromLang === 'ru' && $toLang === 'vladikish') {
            $workingText = preg_replace_callback('/\b(буду|будет|будем|будут)\s+([а-яё]+)\b/ui', function($m) use ($lexicon, &$recognizedCount) {
                $verbAnalysis = self::analyzeRussian($m[2]);
                $verbAnalysis['tense'] = 'future';
                $entry = self::lookupWord($m[2], 'ru', 'vladikish', $lexicon);
                if ($entry) {
                    $recognizedCount++;
                    $target = self::synthesizeTargetWord($entry, 'vladikish', $verbAnalysis, $m[2]);
                    return self::applyCasing($target, $m[0]);
                }
                return $m[0];
            }, $workingText);

            $workingText = preg_replace_callback('/\b(не)\s+([а-яё]+)\b/ui', function($m) use ($lexicon, &$recognizedCount) {
                $entry = self::lookupWord($m[2], 'ru', 'vladikish', $lexicon);
                if ($entry) {
                    $features = self::analyzeRussian($m[2]);
                    $target = self::synthesizeTargetWord($entry, 'vladikish', $features, $m[2]);
                    $recognizedCount += 2;
                    return 'no ' . $target;
                }
                return $m[0];
            }, $workingText);
        }

        // 2b. English -> Vladikish: "will love" -> "Vo-toro", "don't know" -> "no Sabi", "Shiba's book" -> "Libro de Shiba"
        if ($fromLang === 'en' && $toLang === 'vladikish') {
            // Future verbs: will love -> Vo-toro
            $workingText = preg_replace_callback('/\b(will|shall)\s+([a-z]+)\b/ui', function($m) use ($lexicon, &$recognizedCount) {
                $verbAnalysis = self::analyzeEnglish($m[2]);
                $verbAnalysis['tense'] = 'future';
                $entry = self::lookupWord($m[2], 'en', 'vladikish', $lexicon);
                if ($entry) {
                    $recognizedCount++;
                    $target = self::synthesizeTargetWord($entry, 'vladikish', $verbAnalysis, $m[2]);
                    return self::applyCasing($target, $m[0]);
                }
                return $m[0];
            }, $workingText);

            // Negation: do not / don't / does not / doesn't / did not / didn't know -> no Sabi
            $workingText = preg_replace_callback('/\b(do\s+not|don\'t|does\s+not|doesn\'t|did\s+not|didn\'t|cannot|can\'t)\s+([a-z]+)\b/ui', function($m) use ($lexicon, &$recognizedCount) {
                $entry = self::lookupWord($m[2], 'en', 'vladikish', $lexicon);
                if ($entry) {
                    $features = self::analyzeEnglish($m[2]);
                    if (str_starts_with(strtolower($m[1]), 'did')) $features['tense'] = 'past';
                    $target = self::synthesizeTargetWord($entry, 'vladikish', $features, $m[2]);
                    $recognizedCount += 2;
                    return 'no ' . $target;
                }
                return $m[0];
            }, $workingText);

            // Saxon Genitive: Shiba's book -> Libro de Shiba
            $workingText = preg_replace_callback('/\b([a-z]+)\'s\s+([a-z]+)\b/ui', function($m) use ($lexicon, &$recognizedCount) {
                $entry1 = self::lookupWord($m[1], 'en', 'vladikish', $lexicon);
                $entry2 = self::lookupWord($m[2], 'en', 'vladikish', $lexicon);
                $w1 = $entry1 ? self::synthesizeTargetWord($entry1, 'vladikish', [], $m[1]) : $m[1];
                $w2 = $entry2 ? self::synthesizeTargetWord($entry2, 'vladikish', [], $m[2]) : $m[2];
                if ($entry1 || $entry2) {
                    $recognizedCount += 2;
                    return $w2 . ' de ' . $w1;
                }
                return $m[0];
            }, $workingText);

            // Strip English leading definite/indefinite articles before single words: the dogs -> dogs, a star -> star
            $workingText = preg_replace('/\b(the|a|an)\s+/ui', '', $workingText);
        }

        // 2c. Italian -> Vladikish: "ha amato" -> "Toroti", "non so" -> "no Sabi", "libro di Shiba" -> "Libro de Shiba"
        if ($fromLang === 'it' && $toLang === 'vladikish') {
            // Compound past: ha amato -> Toroti, ha mangiato -> Komati, ha aiutato -> Helpiti
            $workingText = preg_replace_callback('/\b(ha|ho|abbiamo|avete|hanno|hai)\s+([a-zàèéìòù]+)\b/ui', function($m) use ($lexicon, &$recognizedCount) {
                $analysis = self::analyzeItalian($m[2]);
                $analysis['tense'] = 'past';
                $entry = self::lookupWord($m[2], 'it', 'vladikish', $lexicon);
                if ($entry) {
                    $recognizedCount++;
                    $target = self::synthesizeTargetWord($entry, 'vladikish', $analysis, $m[2]);
                    return self::applyCasing($target, $m[0]);
                }
                return $m[0];
            }, $workingText);

            // Motion past: è venuto -> Veniti, è andato -> Vadeti
            $workingText = preg_replace_callback('/\b(è|sono|siamo|siete|sei)\s+(venuto|venuta|venuti|venute|arrivato|arrivata|arrivati|arrivate|andato|andata|andati|andate)\b/ui', function($m) use ($lexicon, &$recognizedCount) {
                $analysis = self::analyzeItalian($m[2]);
                $analysis['tense'] = 'past';
                $entry = self::lookupWord($m[2], 'it', 'vladikish', $lexicon);
                if ($entry) {
                    $recognizedCount++;
                    $target = self::synthesizeTargetWord($entry, 'vladikish', $analysis, $m[2]);
                    return self::applyCasing($target, $m[0]);
                }
                return $m[0];
            }, $workingText);

            // Italian Negation: non so -> no Sabi, non mangia -> no Koma
            $workingText = preg_replace_callback('/\b(non)\s+([a-zàèéìòù]+)\b/ui', function($m) use ($lexicon, &$recognizedCount) {
                $entry = self::lookupWord($m[2], 'it', 'vladikish', $lexicon);
                if ($entry) {
                    $features = self::analyzeItalian($m[2]);
                    $target = self::synthesizeTargetWord($entry, 'vladikish', $features, $m[2]);
                    $recognizedCount += 2;
                    return 'no ' . $target;
                }
                return $m[0];
            }, $workingText);

            // Italian Genitive: di / del / della / dei / degli / delle -> de
            $workingText = preg_replace('/\b(di|del|della|dello|dei|degli|delle|d\')\s+/ui', 'de ', $workingText);

            // Strip Italian articles: il, lo, la, i, gli, le, un, uno, una, l'
            $workingText = preg_replace('/\b(il|lo|la|i|le|gli|un|uno|una)\s+/ui', '', $workingText);
            $workingText = preg_replace('/\b(l\')/ui', '', $workingText);
        }

        // Step 3: Question particle 'An':
        $hasAnParticle = false;
        if ($fromLang === 'vladikish' && preg_match('/^An\s+/i', $workingText)) {
            $hasAnParticle = true;
            $workingText = preg_replace('/^An\s+/i', '', $workingText);
        }

        // Step 4: Tokenize Sentence into Words and Punctuation/Spaces
        $rawTokens = preg_split('/([^\p{L}\p{N}\-_]+)/u', $workingText, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $outputParts = [];
        $totalWordTokens = 0;

        foreach ($rawTokens as $tok) {
            // Check if token is a masked phrase placeholder
            if (isset($placeholders[$tok])) {
                $outputParts[] = $placeholders[$tok];
                $recognizedCount++;
                continue;
            }

            // Punctuation, whitespace, or numbers
            if (!preg_match('/[\p{L}]/u', $tok)) {
                $outputParts[] = $tok;
                continue;
            }

            $totalWordTokens++;
            $entry = self::lookupWord($tok, $fromLang, $toLang, $lexicon);

            if ($entry !== null) {
                $recognizedCount++;
                $features = $entry['features'] ?? [];
                $casedWord = self::synthesizeTargetWord($entry, $toLang, $features, $tok);

                $outputParts[] = $casedWord;

                $primaryMeaning = !empty($entry[$toLang]) ? $entry[$toLang][0] : (!empty($entry['ru']) ? $entry['ru'][0] : $entry['vk']);

                $tokensBreakdown[] = [
                    'source' => $tok,
                    'target' => $casedWord,
                    'headword' => $entry['vk'],
                    'pos' => $entry['pos'],
                    'pronunciation' => $entry['pr'] ?? $entry['vk'],
                    'ru_desc' => $primaryMeaning,
                    'example' => $entry['example'] ?? ''
                ];
            } else {
                // Keep original word if unrecognized (Requirement #5 step 3.3)
                $outputParts[] = $tok;
            }
        }

        $finalText = implode('', $outputParts);

        // Restore any remaining placeholders
        foreach ($placeholders as $k => $val) {
            $finalText = str_replace($k, $val, $finalText);
        }

        // Handle 'An' particle question phrasing
        if ($hasAnParticle) {
            if ($toLang === 'ru') {
                $finalText = 'Понимаешь / ' . $finalText;
            } elseif ($toLang === 'en') {
                $finalText = 'Do you ' . lcfirst($finalText);
            }
        }

        // Final Capitalization of sentences
        $finalText = preg_replace_callback('/(^|[.!?]\s+)(\p{L})/u', function($m) {
            return $m[1] . mb_strtoupper($m[2], 'UTF-8');
        }, $finalText);

        return [
            'success' => true,
            'translated_text' => $finalText,
            'tokens' => $tokensBreakdown,
            'phrases_matched' => $phraseMatches,
            'recognized_count' => $recognizedCount,
            'total_words' => $totalWordTokens
        ];
    }
}
