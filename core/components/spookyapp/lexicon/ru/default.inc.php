<?php

include_once 'setting.inc.php';

$_lang['spookyapp'] = 'SpookyApp';
$_lang['spookyapp_menu_desc'] = 'Помощник блогера: поиск тем и генерация чанков';
$_lang['spookyapp_intro_msg'] = 'Вы можете выделять сразу несколько предметов при помощи Shift или Ctrl.';

// -- Items (legacy) --
$_lang['spookyapp_items'] = 'Элементы';
$_lang['spookyapp_item_id'] = 'Id';
$_lang['spookyapp_item_name'] = 'Название';
$_lang['spookyapp_item_description'] = 'Описание';
$_lang['spookyapp_item_active'] = 'Включено';
$_lang['spookyapp_item_create'] = 'Создать элемент';
$_lang['spookyapp_item_update'] = 'Изменить элемент';
$_lang['spookyapp_item_enable'] = 'Включить элемент';
$_lang['spookyapp_items_enable'] = 'Включить элементы';
$_lang['spookyapp_item_disable'] = 'Отключить элемент';
$_lang['spookyapp_items_disable'] = 'Отключить элементы';
$_lang['spookyapp_item_remove'] = 'Удалить элемент';
$_lang['spookyapp_items_remove'] = 'Удалить элементы';
$_lang['spookyapp_item_remove_confirm'] = 'Вы уверены, что хотите удалить этот элемент?';
$_lang['spookyapp_items_remove_confirm'] = 'Вы уверены, что хотите удалить эти элементы?';
$_lang['spookyapp_item_err_name'] = 'Вы должны указать имя элемента.';
$_lang['spookyapp_item_err_ae'] = 'Элемент с таким названием уже существует.';
$_lang['spookyapp_item_err_nf'] = 'Элемент не найден.';
$_lang['spookyapp_item_err_ns'] = 'Элемент не указан.';
$_lang['spookyapp_item_err_remove'] = 'Ошибка при удалении элемента.';
$_lang['spookyapp_item_err_save'] = 'Ошибка при сохранении элемента.';
$_lang['spookyapp_grid_search'] = 'Поиск';
$_lang['spookyapp_grid_actions'] = 'Действия';

// -- Mode Switcher --
$_lang['spookyapp.mode.topicfinder'] = 'О чем писать';
$_lang['spookyapp.mode.chunkgenerator'] = 'Генерация чанков';
$_lang['spookyapp.mode.aichat'] = 'AI Чат';

// -- Topic Finder --
$_lang['spookyapp.topicfinder.title'] = 'Поиск тем';
$_lang['spookyapp.topic.score'] = 'Рейтинг';
$_lang['spookyapp.topic.title'] = 'Заголовок';
$_lang['spookyapp.topic.source'] = 'Источник';
$_lang['spookyapp.topic.category'] = 'Категория';
$_lang['spookyapp.topic.status'] = 'Статус';
$_lang['spookyapp.topic.published_at'] = 'Опубликовано';
$_lang['spookyapp.topic.save_draft'] = 'Сохранить как черновик';
$_lang['spookyapp.topic.save_draft_confirm'] = 'Создать черновик ресурса из';
$_lang['spookyapp.topic.draft_created'] = 'Черновик создан';
$_lang['spookyapp.topic.delete'] = 'Удалить';
$_lang['spookyapp.topic.delete_confirm'] = 'Вы уверены, что хотите удалить';
$_lang['spookyapp.topic.deleted'] = 'Тема удалена';
$_lang['spookyapp.btn.delete_selected'] = ' Удалить выбранные';
$_lang['spookyapp.search'] = ' Поиск...';
$_lang['spookyapp.scoring.recalc_all'] = ' Пересчитать все';
$_lang['spookyapp.ai_ideas.generate'] = ' Генерировать идеи';

// -- Filters --
$_lang['spookyapp.filters.title'] = 'Фильтры';
$_lang['spookyapp.filter.categories'] = 'Категории';
$_lang['spookyapp.filter.sources'] = 'Источники';
$_lang['spookyapp.filter.min_score'] = 'Мин. рейтинг';
$_lang['spookyapp.filter.date_from'] = 'От';
$_lang['spookyapp.filter.date_to'] = 'До';
$_lang['spookyapp.filter.category'] = 'Категория';
$_lang['spookyapp.filter.source'] = 'Источник';
$_lang['spookyapp.filter.all'] = 'Все';
$_lang['spookyapp.filters.applied'] = 'Фильтры применены';
$_lang['spookyapp.filters.reset'] = 'Фильтры сброшены';

// -- Buttons --
$_lang['spookyapp.btn.apply'] = 'Применить';
$_lang['spookyapp.btn.reset'] = 'Сбросить';
$_lang['spookyapp.btn.refresh'] = 'Обновить';
$_lang['spookyapp.btn.get_new_topics'] = 'Получить новые темы';
$_lang['spookyapp.btn.rewrite_ai'] = 'Рерайт через AI';

// -- Rewrite AI Window --
$_lang['spookyapp.rewrite.title']              = 'Рерайт через AI';
$_lang['spookyapp.rewrite.topic']              = 'Тема';
$_lang['spookyapp.rewrite.mode']               = 'Режим';
$_lang['spookyapp.rewrite.mode.article']       = 'Статья';
$_lang['spookyapp.rewrite.mode.news']          = 'Новость';
$_lang['spookyapp.rewrite.mode.social']        = 'Соцсети';
$_lang['spookyapp.rewrite.mode.seo']           = 'SEO-пакет';
$_lang['spookyapp.rewrite.mode.title']         = '5 заголовков';
$_lang['spookyapp.rewrite.mode.custom']        = 'Свой промпт';
$_lang['spookyapp.rewrite.tone']               = 'Тон';
$_lang['spookyapp.rewrite.tone.neutral']       = 'Нейтральный';
$_lang['spookyapp.rewrite.tone.formal']        = 'Деловой';
$_lang['spookyapp.rewrite.tone.casual']        = 'Разговорный';
$_lang['spookyapp.rewrite.tone.enthusiastic']  = 'Эмоциональный';
$_lang['spookyapp.rewrite.tone.analytical']    = 'Аналитический';
$_lang['spookyapp.rewrite.language']           = 'Язык';
$_lang['spookyapp.rewrite.min_length']         = 'Минимум символов';
$_lang['spookyapp.rewrite.max_length']         = 'Максимум символов';
$_lang['spookyapp.rewrite.temperature']        = 'Температура (0–1)';
$_lang['spookyapp.rewrite.save_to_topic']      = 'Сохранить результат в тему';
$_lang['spookyapp.rewrite.force']              = 'Перегенерировать, если уже есть';
$_lang['spookyapp.rewrite.custom_prompt_hint'] = 'Напишите свой промпт. Доступны: {title}, {description}, {source}, {category}.';
$_lang['spookyapp.rewrite.result_title']       = 'Результат рерайта';
$_lang['spookyapp.btn.save_draft'] = 'Сохранить как черновик';
$_lang['spookyapp.btn.copy_url'] = 'Копировать URL';
$_lang['spookyapp.btn.copy_data'] = 'Копировать данные';
$_lang['spookyapp.btn.translate'] = 'Перевести';
$_lang['spookyapp.btn.delete'] = 'Удалить';
$_lang['spookyapp.btn.export_csv'] = 'Экспорт в CSV';
$_lang['spookyapp.btn.settings'] = 'Настройки';
$_lang['spookyapp.btn.fetch_topics'] = 'Загрузить темы';
$_lang['spookyapp.btn.cancel'] = 'Отмена';
$_lang['spookyapp.btn.search_news'] = 'Поиск новостей';
$_lang['spookyapp.btn.scoring'] = 'Пересчитать рейтинг';
$_lang['spookyapp.btn.generate_ideas'] = 'Генерация идей';

// -- Details Panel --
$_lang['spookyapp.details.title'] = 'Детали темы';
$_lang['spookyapp.details.select_topic'] = 'Выберите тему для просмотра деталей';
$_lang['spookyapp.details.score'] = 'Рейтинг';
$_lang['spookyapp.details.published'] = 'Опубликовано';
$_lang['spookyapp.details.description'] = 'Описание';
$_lang['spookyapp.details.no_description'] = 'Описание отсутствует';
$_lang['spookyapp.details.url'] = 'URL источника';
$_lang['spookyapp.details.metadata'] = 'Метаданные';
$_lang['spookyapp.details.cached_at'] = 'Кешировано';
$_lang['spookyapp.details.no_url'] = 'URL недоступен для этой темы.';
$_lang['spookyapp.details.url_copied'] = 'URL скопирован в буфер обмена';

// -- Get Topics Window --
$_lang['spookyapp.get_topics.title'] = 'Получить новые темы';
$_lang['spookyapp.get_topics.sources'] = 'Источники';
$_lang['spookyapp.get_topics.categories'] = 'Категории';
$_lang['spookyapp.get_topics.min_score'] = 'Минимальный рейтинг';
$_lang['spookyapp.get_topics.limit'] = 'Лимит (макс. тем)';
$_lang['spookyapp.get_topics.loading'] = 'Загрузка тем...';
$_lang['spookyapp.get_topics.select_source'] = 'Выберите хотя бы один источник.';
$_lang['spookyapp.get_topics.fetched'] = 'Темы загружены';

// -- General --
$_lang['spookyapp.success'] = 'Успешно';
$_lang['spookyapp.error'] = 'Ошибка';

// -- Chunk Generator --
$_lang['spookyapp.chunkgenerator.tab_movies'] = 'Фильмы';
$_lang['spookyapp.chunkgenerator.tab_cinema'] = 'Кино';
$_lang['spookyapp.chunkgenerator.tab_tv'] = 'Сериалы';
$_lang['spookyapp.chunkgenerator.tab_person'] = 'Персона';
$_lang['spookyapp.chunkgenerator.tab_games'] = 'Игры';
$_lang['spookyapp.chunkgenerator.tab_devices'] = 'Устройства';
$_lang['spookyapp.chunkgenerator.tab_sports'] = 'Спорт';
$_lang['spookyapp.chunkgenerator.tab_products'] = 'Товары';
$_lang['spookyapp.chunkgenerator.tab_sports'] = 'Спорт';
$_lang['spookyapp.chunkgenerator.tab_sport'] = 'Спорт';
$_lang['spookyapp.chunkgenerator.voiceover'] = 'Озвучка';
$_lang['spookyapp.chunkgenerator.tab_github'] = 'GitHub';
$_lang['spookyapp.chunkgenerator.details'] = 'Детали';
$_lang['spookyapp.chunkgenerator.no_results'] = 'Нет результатов. Используйте форму поиска выше.';
$_lang['spookyapp.chunkgenerator.col_title'] = 'Название';
$_lang['spookyapp.chunkgenerator.col_original_title'] = 'Оригинальное название';
$_lang['spookyapp.chunkgenerator.col_year'] = 'Год';
$_lang['spookyapp.chunkgenerator.col_rating'] = 'Рейтинг';
$_lang['spookyapp.chunkgenerator.col_votes'] = 'Голоса';
$_lang['spookyapp.chunkgenerator.query'] = 'Поиск';
$_lang['spookyapp.chunkgenerator.query_empty'] = 'Введите поисковый запрос...';
$_lang['spookyapp.chunkgenerator.year'] = 'Год';
$_lang['spookyapp.chunkgenerator.year_any'] = 'Любой';
$_lang['spookyapp.chunkgenerator.search_btn'] = 'Искать';
$_lang['spookyapp.chunkgenerator.clear_btn'] = 'Очистить';
$_lang['spookyapp.chunkgenerator.subtype'] = 'Подтип';
$_lang['spookyapp.chunkgenerator.load_options'] = 'Загрузить:';
$_lang['spookyapp.chunkgenerator.reload'] = 'Перезагрузить';
$_lang['spookyapp.chunkgenerator.preview_chunk'] = 'Предпросмотр';
$_lang['spookyapp.chunkgenerator.save_to_db'] = 'Сохранить в БД';
$_lang['spookyapp.chunkgenerator.copy_code'] = 'Копировать код';
$_lang['spookyapp.chunkgenerator.reset'] = 'Сброс';
$_lang['spookyapp.chunkgenerator.select_item'] = 'Выберите элемент из результатов поиска';
$_lang['spookyapp.chunkgenerator.dblclick_hint'] = 'Двойной клик или кнопка "Детали"';
$_lang['spookyapp.chunkgenerator.loading_details'] = 'Загрузка деталей...';
$_lang['spookyapp.chunkgenerator.translate'] = 'Перевести';
$_lang['spookyapp.chunkgenerator.voice_over'] = 'Озвучить';
$_lang['spookyapp.chunkgenerator.generate'] = 'Генерация чанка';
$_lang['spookyapp.chunkgenerator.opt_cast'] = 'Актёры';
$_lang['spookyapp.chunkgenerator.opt_crew'] = 'Съёмочная группа';
$_lang['spookyapp.chunkgenerator.opt_screenshots'] = 'Скриншоты';
$_lang['spookyapp.chunkgenerator.opt_similar'] = 'Похожие';
$_lang['spookyapp.chunkgenerator.opt_seasons'] = 'Сезоны';
$_lang['spookyapp.chunkgenerator.opt_movies'] = 'Фильмы';
$_lang['spookyapp.chunkgenerator.opt_tv'] = 'Сериалы';
$_lang['spookyapp.chunkgenerator.opt_images'] = 'Изображения';
$_lang['spookyapp.chunkgenerator.opt_offers'] = 'Предложения';
$_lang['spookyapp.chunkgenerator.export_json'] = 'Экспорт в JSON';
$_lang['spookyapp.chunkgenerator.prev_page'] = 'Пред.';
$_lang['spookyapp.chunkgenerator.next_page'] = 'След.';
$_lang['spookyapp.chunkgenerator.generate_code'] = 'Сгенерировать код';

// -- Возрастной рейтинг --
$_lang['spookyapp.chunkgenerator.age_rating'] = 'Возрастной рейтинг';

// Описания US MPAA / TV Parental Guidelines
$_lang['spookyapp.age_rating.G']     = 'G — Для всех возрастов.';
$_lang['spookyapp.age_rating.PG']    = 'PG — Рекомендуется присутствие родителей. Для зрителей от 10 лет.';
$_lang['spookyapp.age_rating.PG-13'] = 'PG-13 — Строгое предупреждение родителям. Для зрителей от 13 лет.';
$_lang['spookyapp.age_rating.R']     = 'R — Ограниченный доступ. Для зрителей от 17 лет.';
$_lang['spookyapp.age_rating.NC-17'] = 'NC-17 — Только для взрослых. От 18 лет.';
$_lang['spookyapp.age_rating.NR']    = 'NR — Без рейтинга.';
// TV Parental Guidelines (сериалы)
$_lang['spookyapp.age_rating.TV-Y']   = 'TV-Y — Для детей любого возраста.';
$_lang['spookyapp.age_rating.TV-Y7']  = 'TV-Y7 — Для детей от 7 лет.';
$_lang['spookyapp.age_rating.TV-G']   = 'TV-G — Для всей семьи.';
$_lang['spookyapp.age_rating.TV-PG']  = 'TV-PG — Рекомендуется присутствие родителей. От 10 лет.';
$_lang['spookyapp.age_rating.TV-14']  = 'TV-14 — Строгое предупреждение родителям. От 14 лет.';
$_lang['spookyapp.age_rating.TV-MA'] = 'TV-MA — Только для взрослой аудитории. От 17 лет.';
