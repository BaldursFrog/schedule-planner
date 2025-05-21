<?php

// Конфигурация для PHP-CS-Fixer

// Создаем объект Finder, чтобы указать, какие файлы и папки нужно проверять.
$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__) // Искать в текущей директории (корень проекта)
    ->exclude('bootstrap/cache') // Исключить папку кеша Bootstrap
    ->exclude('node_modules')    // Исключить папку node_modules (если есть)
    ->exclude('storage')         // Исключить папку storage
    ->exclude('vendor')          // ОБЯЗАТЕЛЬНО исключить папку vendor
    ->path('app')                // Включить папку app
    ->path('config')             // Включить папку config
    ->path('database/factories') // Включить папку factories
    ->path('database/migrations')// Включить папку migrations (можно исключить, если не хочешь их форматировать)
    ->path('database/seeders')  // Включить папку seeders
    ->path('routes')             // Включить папку routes
    ->path('tests')              // Включить папку tests
    ->name('*.php')              // Искать только файлы с расширением .php
    ->notName('*.blade.php')     // Исключить Blade шаблоны (их форматируют другие инструменты, если нужно)
    ->notName('_ide_helper.php') // Исключить файлы для IDE
    ->notName('_ide_helper_models.php')
    ->notName('.phpstorm.meta.php')
    ->ignoreDotFiles(true)       // Игнорировать скрытые файлы (начинающиеся с точки)
    ->ignoreVCS(true);           // Игнорировать файлы систем контроля версий (например, из .git)

// Создаем объект конфигурации
$config = new PhpCsFixer\Config();

// Устанавливаем правила и Finder
return $config
    ->setRules([
        // Используем набор правил PSR-12
        '@PSR12' => true,

        // Дополнительные популярные правила (можешь добавлять/убирать по вкусу):
        'array_syntax' => ['syntax' => 'short'], // Использовать короткий синтаксис массивов [] вместо array()
        'binary_operator_spaces' => [ // Пробелы вокруг бинарных операторов
            'default' => 'single_space', // Один пробел по умолчанию
            'operators' => ['=>' => 'align_single_space_minimal', // Выравнивать => в массивах (минимально)
                            // '=' => 'align_single_space_minimal' // Можно добавить и для =
                           ]
        ],
        'blank_line_after_namespace' => true, // Пустая строка после объявления namespace
        'blank_line_after_opening_tag' => true, // Пустая строка после <?php
        //'blank_line_before_statement' => [ // Пустая строка перед определенными конструкциями
        //    'statements' => ['return', 'try', 'catch', 'finally', 'throw', 'if', 'foreach', 'while', 'do', 'switch'],
        //],
        'braces_position' => [
                'control_structures_opening_brace' => 'same_line',
                'functions_opening_brace' => 'next_line_unless_newline_at_signature_end', // Тоже можно обновить для консистентности, если нужно
                'anonymous_functions_opening_brace' => 'same_line',
                'classes_opening_brace' => 'next_line_unless_newline_at_signature_end', // <<<--- ИЗМЕНЕНО ЗДЕСЬ
                'anonymous_classes_opening_brace' => 'same_line', // Это значение обычно 'same_line'
                'allow_single_line_empty_anonymous_classes' => false,
                'allow_single_line_anonymous_functions' => false,
            ],
        'cast_spaces' => ['space' => 'single'], // Один пробел после операторов приведения типов (int), (string)
        'class_attributes_separation' => [ // Разделение атрибутов класса, методов и свойств
            'elements' => [
                'const' => 'one', // Одна пустая строка между константами
                'method' => 'one',// Одна пустая строка между методами
                'property' => 'one',// Одна пустая строка между свойствами
                'trait_import' => 'none',
                'case' => 'none',
            ],
        ],
        'class_definition' => [ // Определение класса
            'multi_line_extends_each_single_line' => true, // Каждый extends/implements на новой строке, если их много
            'single_item_single_line' => true,
            'space_before_parenthesis' => true, // Пробел перед скобками конструктора анонимного класса
        ],
        'concat_space' => ['spacing' => 'one'], // Один пробел вокруг оператора конкатенации .
        'declare_equal_normalize' => ['space' => 'single'], // Нормализация пробелов в declare(strict_types=1)
        'elseif' => true, // Использовать elseif вместо else if
        'encoding' => true, // Все файлы должны быть в UTF-8
        'full_opening_tag' => true, // Использовать полную форму <?php
        'function_declaration' => ['closure_function_spacing' => 'one'], // Пробел в замыканиях
        //'type_colon_spaces' => ['before' => 'none', 'after' => 'single'], // Пробелы вокруг двоеточия при указании типа возвращаемого значения
        'include' => true, // Нормализация include/require
        'increment_style' => ['style' => 'post'], // Использовать постфиксный инкремент/декремент ($i++)
        'indentation_type' => true, // Использовать 4 пробела для отступов
        'lambda_not_used_import' => true, // Удалять неиспользуемые переменные, переданные в замыкание через use()
        'linebreak_after_opening_tag' => true, // Перенос строки после <?php
        'lowercase_cast' => true, // Операторы приведения типов в нижнем регистре: (int) вместо (INT)
        'lowercase_keywords' => true, // Ключевые слова PHP в нижнем регистре (true, false, null, if, else...)
        'lowercase_static_reference' => true, // static:: в нижнем регистре
        'magic_method_casing' => true, // Магические методы в camelCase (__construct, __toString)
        'magic_constant_casing' => true, // Магические константы в верхнем регистре (__FILE__, __DIR__)
        'method_argument_space' => [ // Пробелы в аргументах методов
            'on_multiline' => 'ensure_fully_multiline', // Если аргументы на нескольких строках, то каждый на своей
            'after_heredoc' => true,
        ],
        'native_function_casing' => true, // Имена встроенных функций PHP в нижнем регистре (strtolower, array_map)
        'no_alias_language_construct_call' => true, // Не использовать алиасы для языковых конструкций
        'no_alternative_syntax' => true, // Не использовать альтернативный синтаксис для управляющих структур (никаких endif;)
        'no_binary_string' => true, // Не использовать бинарные строки b""
        'no_blank_lines_after_class_opening' => true, // Нет пустых строк после открывающей скобки класса {
        'no_blank_lines_after_phpdoc' => true, // Нет пустых строк после PHPDoc блока
        'no_closing_tag' => true, // В файлах, содержащих только PHP, не должно быть закрывающего тега 
        'no_empty_phpdoc' => true, // Удалять пустые PHPDoc блоки
        'no_empty_statement' => true, // Удалять пустые точки с запятой ;
        'no_extra_blank_lines' => [ // Удалять лишние пустые строки
            'tokens' => [
                'extra',
                'throw',
                'use',
                'use_trait',
                // 'curly_brace_block', // Можно раскомментировать для более агрессивного удаления
                // 'parenthesis_brace_block',
                // 'square_brace_block',
            ],
        ],
        'no_leading_import_slash' => true, // Не использовать \ в начале use Foo\Bar;
        'no_leading_namespace_whitespace' => true, // Нет пробелов перед объявлением namespace
        'no_mixed_echo_print' => ['use' => 'echo'], // Использовать только echo, не print
        'no_multiline_whitespace_around_double_arrow' => true, // Нет многострочных пробелов вокруг =>
        'no_short_bool_cast' => true, // Не использовать !! для приведения к bool
        'no_singleline_whitespace_before_semicolons' => true, // Нет пробелов перед ;
        'no_spaces_after_function_name' => true, // Нет пробелов после имени функции/метода перед (
        'no_spaces_around_offset' => ['positions' => ['inside', 'outside']], // Нет пробелов вокруг индексов массива $arr[ $i ]
        'no_trailing_comma_in_singleline_function_call' => true, // Нет висячей запятой в однострочном вызове функции
        'no_trailing_whitespace' => true, // Удалять пробелы в конце строк (аналог PHPCS правила)
        'no_trailing_whitespace_in_comment' => true, // Удалять пробелы в конце комментариев
        'no_unneeded_control_parentheses' => [ // Удалять ненужные скобки вокруг управляющих структур
            'statements' => ['break', 'clone', 'continue', 'echo_print', 'return', 'switch_case', 'yield'],
        ],
        'no_unneeded_curly_braces' => ['namespaces' => true], // Удалять ненужные фигурные скобки для namespace
        'no_unset_cast' => true, // Не использовать (unset)
        'no_unused_imports' => true, // Удалять неиспользуемые use импорты (ОЧЕНЬ ПОЛЕЗНО!)
        'no_whitespace_before_comma_in_array' => true, // Нет пробела перед запятой в массиве
        'no_whitespace_in_blank_line' => true, // Пустые строки не должны содержать пробелов
        'normalize_index_brace' => true, // Использовать $a[] вместо $a []
        'object_operator_without_whitespace' => true, // Нет пробелов вокруг -> и ?->
        'ordered_imports' => ['sort_algorithm' => 'alpha', 'imports_order' => ['const', 'class', 'function']], // Сортировать use импорты (сначала константы, потом классы, потом функции, внутри каждой группы по алфавиту)
        'phpdoc_align' => ['align' => 'vertical'], // Выравнивание аннотаций в PHPDoc
        'phpdoc_indent' => true, // Отступы в PHPDoc
        'phpdoc_no_access' => true, // Не использовать @access в PHPDoc
        'phpdoc_no_package' => true, // Не использовать @package в PHPDoc
        'phpdoc_no_useless_inheritdoc' => true, // Удалять ненужные @inheritdoc
        'phpdoc_scalar' => true, // Использовать bool, int, float, string, null вместо boolean, integer, double... в PHPDoc
        'phpdoc_separation' => true, // Разделение аннотаций в PHPDoc
        'phpdoc_single_line_var_spacing' => true, // Пробелы в однострочном @var
        'phpdoc_summary' => true, // Краткое описание PHPDoc должно заканчиваться точкой.
        'phpdoc_to_comment' => false, // Не преобразовывать PHPDoc в обычные комментарии
        'phpdoc_trim' => true, // Обрезать лишние пробелы в PHPDoc
        'phpdoc_types' => true, // Правильное написание типов в PHPDoc
        'phpdoc_var_without_name' => true, // @var тип без имени переменной (если она очевидна)
        'return_type_declaration' => ['space_before' => 'none'], // Пробелы в объявлении типа возвращаемого значения
        'short_scalar_cast' => true, // Использовать (int) вместо (integer)
        'single_blank_line_at_eof' => true, // Одна пустая строка в конце файла (аналог PHPCS)
        'single_class_element_per_statement' => ['elements' => ['const', 'property']], // Одно свойство/константа на строку
        'single_import_per_statement' => true, // Один use на строку
        'single_line_after_imports' => true, // Одна пустая строка после блока use
        'single_quote' => true, // Использовать одинарные кавычки для строк, где это возможно
        'space_after_semicolon' => ['remove_in_empty_for_expressions' => true], // Пробел после ;
        'standardize_not_equals' => true, // Использовать <> вместо != (или наоборот, если предпочитаешь !=, поставь false)
        'switch_case_semicolon_to_colon' => true, // В switch case использовать : вместо ;
        'switch_case_space' => true, // Пробел после case
        'ternary_operator_spaces' => true, // Пробелы вокруг тернарного оператора ?:
        'trailing_comma_in_multiline' => [ // Висячая запятая в многострочных массивах и списках аргументов
            'elements' => ['arrays', 'arguments', 'parameters'],
        ],
        'trim_array_spaces' => true, // Удалять пробелы в начале/конце массивов
        'unary_operator_spaces' => true, // Пробелы вокруг унарных операторов (!, ++, --)
        'visibility_required' => ['elements' => ['property', 'method', 'const']], // Явно указывать видимость (public, protected, private)
        'whitespace_after_comma_in_array' => true, // Пробел после запятой в массиве
    ])
    ->setIndent("    ") // Использовать 4 пробела для отступа
    ->setLineEnding("\n") // Использовать Unix-style окончания строк (LF)
    ->setFinder($finder); // Применить Finder к конфигурации