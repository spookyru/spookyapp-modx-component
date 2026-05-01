# SpookyApp

Дополнение (Extra) для MODX Revolution 3.x. (основано на ModExtra3 https://github.com/modx-pro/ModExtra3). Компонент агрегирует и отображает контент из внешних источников: фильмы, сериалы, персоны, игры, устройства, товары, спортивные данные. Данные приходят через внешние API (TMDB, RAWG, спортивные API и др.), нормализуются и кешируются в собственных таблицах MySQL для ускорения работы и снижения количества запросов к сторонним сервисам.

Данные получаются через внешние API (TMDB, RAWG, спортивные API и др.), нормализуются и кешируются в собственных таблицах MySQL для ускорения работы и снижения количества запросов к сторонним сервисам.

Дополнительно используется Yandex Cloud (AI Studio / SpeechKit / Translate) для:

* перевода данных
* рерайта текстов
* генерации озвучки

## Важное замечание по разработке

В процессе разработки активно использовались современные AI-инструменты:
* Claude (Sonnet 4.5, Opus)
* GPT (включая 5.x)
Они применялись в первую очередь для:
* генерации ExtJS интерфейсов
* ускорения написания внутренней логики
* автоматизации рутинных операций

Это позволило существенно ускорить разработку, но также могло повлиять на стиль и структуру кода. Если вы обнаружите нестандартные или спорные решения — это ожидаемо.

Компонент использует сторонние API как основной источник контента.

Я **не несу ответственности** за:

* точность данных
* актуальность
* доступность API
* изменения в форматах ответов
* юридический статус контента

Причина выбора внешних API:

* высокая скорость получения данных
* отсутствие необходимости ручного наполнения
* широкий охват тематик (кино, игры, спорт, новости)
* наличие структурированных данных

Yandex Cloud используется в первую очередь для:

* перевода (включая массовую обработку полей)
* рерайта текстов
* озвучки через SpeechKit

Выбор сделан на основе практического опыта, ранее использовался SpeechKit для озвучивания контента (в других проектах, в частности озвучки расписания на сайте кинотеатра), качество синтеза речи оказалось достаточным для продакшена, удобная интеграция через API

## История и идея проекта

Основная идея компонента появилась из практической задачи:
При разработке  панели администратора для контента для кинотеатра использовались внешние API и SpeechKit для озвучивания. Мне понравился такой подход и к тому же часто при написании статей для блога хочется получать некую точную информацию, которую постоянно выдергивать с postman иногда неудобно и хотелось все иметь в рамках одной админки modx. Сейчас развитие AI-инструментов (в том числе интеграция в Visual Studio Code через GitHub Copilot и аналогичные решения) позволило значительно упростить разработку и масштабировать эту идею. 

В данном случае используются следующие API (большая часть из RapidAPI) и доступ к ним задается из системных настроек MODX:

```
spookyapp.yandex_api_key        — Yandex Cloud API key (GPT, Translate, SpeechKit)
spookyapp.yandex_folder_id      — Yandex Cloud Folder ID
spookyapp.tmdb_bearer_token     — TMDB Bearer Token
spookyapp.rawg_api_key          — RAWG (игры)
spookyapp.rapidapi_key          — общий RapidAPI key
spookyapp.mobileapi_token       — MobileApi.dev token
spookyapp.football_api_key      — api-football (RapidAPI)
spookyapp.flashlive_api_key     — FlashLive Sports (RapidAPI)
spookyapp.sportapi7_api_key     — SportAPI7 (RapidAPI)
spookyapp.reddit34_rapidapi_key — Reddit34 (RapidAPI)
spookyapp.github_token          — GitHub PAT (опционально, лимит без него ~60 req/h)
spookyapp.thenewsapi_key        — TheNewsAPI
spookyapp.newsdata_key          — NewsData.io
```

## Ограничения

* Компонент находится в стадии активной разработки
* Возможны ошибки, нестабильная работа и несовместимости
* Развертывание может быть нетривиальным (ориентирован на опытных пользователей MODX)
* Некоторые части требуют ручной доработки под конкретный проект

Этот компонент публикуется в первую очередь как:

* рабочий инструмент
* экспериментальная база
* личный проект
* оставить хоть что-то в разработке под modx

Он **не ориентирован на массовое использование "из коробки"**. 

Если вы планируете использовать его в продакшене — потребуется:

* понимание архитектуры MODX
* навыки работы с API
* готовность адаптировать код под себя


## Содержание

1. [Требования](#требования)
2. [Установка](#установка)
3. [Системные настройки](#системные-настройки)
4. [Сниппеты](#сниппеты)
   - [SpookyApp](#spookyapp-1)
   - [SpookyAppMovie](#spookyappmovie)
   - [SpookyAppTvShow](#spookyapptvshow)
   - [SpookyAppPerson](#spookyappperson)
   - [SpookyAppGame](#spookyappgame)
   - [SpookyAppDevice](#spookyappdevice)
   - [SpookyAppProduct](#spookyappproduct)
   - [SpookyAppChunk](#spookyappchunk)
5. [Чанки-шаблоны](#чанки-шаблоны)
6. [Таблицы БД](#таблицы-бд)
7. [Администрирование](#администрирование)
8. [Сборка транспортного пакета](#сборка-транспортного-пакета)

---

## Требования

| Компонент | Версия |
|---|---|
| MODX Revolution | 3.x |
| PHP | 8.1+ |
| MySQL / MariaDB | 5.7+ / 10.4+ |

---

## Установка

1. - тут должен был быть транспортный пакет
2. - Установить его через загрузку пакетов
3. Заполнить системные настройки (API-ключи) в разделе **Система → Системные настройки → SpookyApp**.

---

## Системные настройки

Все ключи хранятся исключительно в системных настройках MODX. В коде никаких явных значений нет.

### Основные

| Ключ | Описание |
|---|---|
| `spookyapp.blog_theme` | Тема оформления блога |

### Яндекс

| Ключ | Описание |
|---|---|
| `spookyapp.yandex_api_key` | API-ключ Яндекс (дуступы к модели переводов, написания текстов и speechKit) |
| `spookyapp.yandex_folder_id` | ID папки/каталога в Яндекс Облаке |

### Медиа и игры

| Ключ | Описание |
|---|---|
| `spookyapp.tmdb_bearer_token` | Bearer-токен The Movie Database (TMDB) |
| `spookyapp.rawg_api_key` | API-ключ RAWG (игры) |

### Прочие внешние API

| Ключ | Описание |
|---|---|
| `spookyapp.rapidapi_key` | Мастер-ключ RapidAPI |
| `spookyapp.mobileapi_token` | Токен API мобильных устройств |
| `spookyapp.github_token` | GitHub Personal Access Token |
| `spookyapp.reddit34_rapidapi_key` | Ключ Reddit-обёртки через RapidAPI |

### Новости

| Ключ | Описание |
|---|---|
| `spookyapp.thenewsapi_key` | API-ключ TheNewsAPI |
| `spookyapp.newsdata_key` | API-ключ NewsData.io |

### Спорт

| Ключ | Описание |
|---|---|
| `spookyapp.football_api_key` | Ключ API-Football |
| `spookyapp.flashlive_api_key` | Ключ FlashLive Sports API |
| `spookyapp.sportapi7_api_key` | Ключ SportAPI7 |

---

## Сниппеты

### SpookyApp

Базовый сниппет. Выводит элементы из таблицы `spookyapp_item` через произвольный чанк.

```
[[SpookyApp? &tpl=`tpl.SpookyApp.item` &limit=`10` &sortby=`name`]]
```

| Параметр | Тип | По умолчанию | Описание |
|---|---|---|---|
| `tpl` | string | `Item` | Имя чанка-шаблона |
| `sortby` | string | `name` | Поле сортировки |
| `sortdir` | string | `ASC` | Направление: `ASC` / `DESC` |
| `limit` | int | `5` | Количество записей |
| `outputSeparator` | string | `\n` | Разделитель между элементами |
| `toPlaceholder` | string | `` | Если задано — результат пишется в плейсхолдер, не возвращается |

---

### SpookyAppMovie

Выводит информацию о фильме из TMDB или из локального кеша БД.

```
[[SpookyAppMovie? &id=`550` &type=`db`]]
[[SpookyAppMovie? &id=`550` &type=`tmdb` &nocache=`1`]]
[[SpookyAppMovie? &id=`550` &template=`myMovieChunk`]]
```

| Параметр | Тип | По умолчанию | Описание |
|---|---|---|---|
| `id` | int | — | **Обязателен.** TMDB ID фильма |
| `type` | string | `db` | `db` — из кеша, `tmdb` — свежий запрос к API |
| `template` | string | `tpl.SpookyApp.movie` | Кастомный чанк-шаблон |
| `nocache` | bool | `false` | Принудительно игнорировать кеш |

**Плейсхолдеры чанка:** `id`, `title`, `original_title`, `overview`, `poster_path`, `backdrop_path`, `release_date`, `runtime`, `genres`, `credits`, `videos`, `images`, `vote_average`, `vote_count`, `tagline`, `imdb_id`, `external_ids`.

---

### SpookyAppTvShow

Выводит информацию о сериале из TMDB или кеша.

```
[[SpookyAppTvShow? &id=`1399` &type=`db`]]
[[SpookyAppTvShow? &id=`1399` &type=`tmdb`]]
```

| Параметр | Тип | По умолчанию | Описание |
|---|---|---|---|
| `id` | int | — | **Обязателен.** TMDB ID сериала |
| `type` | string | `db` | `db` — из кеша, `tmdb` — API |
| `template` | string | `tpl.SpookyApp.tvshow` | Чанк-шаблон |
| `nocache` | bool | `false` | Игнорировать кеш |

**Плейсхолдеры чанка:** `id`, `name`, `original_name`, `overview`, `first_air_date`, `poster_path`, `backdrop_path`, `genres`, `seasons`, `number_of_seasons`, `number_of_episodes`, `networks`, `credits`, `vote_average`, `tagline`, `status`.

---

### SpookyAppPerson

Выводит данные актёра/режиссёра/персоны из TMDB или кеша.

```
[[SpookyAppPerson? &id=`6193` &type=`db`]]
```

| Параметр | Тип | По умолчанию | Описание |
|---|---|---|---|
| `id` | int | — | **Обязателен.** TMDB ID персоны |
| `type` | string | `db` | `db` — кеш, `tmdb` — API |
| `template` | string | `tpl.SpookyApp.person` | Чанк-шаблон |
| `nocache` | bool | `false` | Игнорировать кеш |

**Плейсхолдеры чанка:** `id`, `name`, `biography`, `birthday`, `deathday`, `place_of_birth`, `popularity`, `profile_path`, `imdb_id`, `movie_credits`, `wikidata_id`, `instagram_id`, `twitter_id`, `youtube_id`.

---

### SpookyAppGame

Выводит информацию об игре из RAWG API или кеша.

```
[[SpookyAppGame? &id=`3498` &type=`db`]]
[[SpookyAppGame? &id=`3498` &type=`rawg`]]
```

| Параметр | Тип | По умолчанию | Описание |
|---|---|---|---|
| `id` | int | — | **Обязателен.** RAWG ID игры |
| `type` | string | `db` | `db` — кеш, `rawg` — API RAWG |
| `template` | string | `tpl.SpookyApp.game` | Чанк-шаблон |
| `nocache` | bool | `false` | Игнорировать кеш |

**Плейсхолдеры чанка:** `id`, `name`, `slug`, `summary`, `cover`, `genres`, `platforms`, `rating`, `rating_count`, `screenshots`, `websites`, `first_release_date`.

---

### SpookyAppDevice

Выводит информацию об устройстве (смартфон, планшет и т.д.) из кеша или API устройств.

```
[[SpookyAppDevice? &id=`123`]]
[[SpookyAppDevice? &id=`123` &type=`api` &template=`myDeviceChunk`]]
```

| Параметр | Тип | По умолчанию | Описание |
|---|---|---|---|
| `id` | string/int | — | **Обязателен.** ID устройства |
| `type` | string | `db` | `db` — кеш, `api` — свежий запрос |
| `template` | string | `tpl.SpookyApp.device` | Чанк-шаблон |
| `nocache` | bool | `false` | Игнорировать кеш |

---

### SpookyAppProduct

Выводит информацию о товаре из кеша или API (Amazon, маркетплейс).

```
[[SpookyAppProduct? &id=`456`]]
```

| Параметр | Тип | По умолчанию | Описание |
|---|---|---|---|
| `id` | int | — | **Обязателен.** ID товара в БД |
| `type` | string | `db` | Источник данных |
| `template` | string | `tpl.SpookyApp.product` | Чанк-шаблон |
| `nocache` | bool | `false` | Игнорировать кеш |

---

### SpookyAppChunk

Универсальный генератор чанков (ChunkGenerator). Используется для программного создания и обновления чанков через API SpookyApp.

```
[[!SpookyAppChunk]]
```

Сниппет предназначен для внутреннего использования и вызова из процессоров. Как правило, вызывается через механизм поиска и обработки чанков (`ChunkGeneratorService`).

---

## Чанки-шаблоны

Чанки доступны сразу после установки. Их можно редактировать в менеджере MODX.

| Имя чанка | Файл | Назначение |
|---|---|---|
| `tpl.SpookyApp.item` | `item.tpl` | Базовый элемент (общий) |
| `tpl.SpookyApp.movie` | `movie.chunk.tpl` | Карточка фильма |
| `tpl.SpookyApp.tvshow` | `tvshow.chunk.tpl` | Карточка сериала |
| `tpl.SpookyApp.person` | `person.chunk.tpl` | Карточка персоны |
| `tpl.SpookyApp.game` | `game.chunk.tpl` | Карточка игры |
| `tpl.SpookyApp.device` | `device.chunk.tpl` | Карточка устройства |
| `tpl.SpookyApp.product` | `product.chunk.tpl` | Карточка товара |

Исходные файлы чанков: `core/components/spookyapp/elements/chunks/`.

### Создание кастомного чанка

Создайте свой чанк в менеджере и передайте его имя через параметр `&template`:

```
[[SpookyAppMovie? &id=`550` &template=`myMovieCard`]]
```

В чанке используйте плейсхолдеры в синтаксисе MODX (`[[+field_name]]`).

---

## Таблицы БД

Все таблицы создаются автоматически при установке пакета.

| Таблица | Описание |
|---|---|
| `spookyapp_tmdb_movies` | Фильмы (TMDB) |
| `spookyapp_tmdb_series` | Сериалы (TMDB) |
| `spookyapp_tmdb_person` | Персоны (TMDB) |
| `spookyapp_tmdb_trends` | Трендовые позиции (TMDB) |
| `spookyapp_tmdb_releases` | Релизы фильмов |
| `spookyapp_tmdb_upcoming` | Предстоящие релизы |
| `spookyapp_tmdb_genres` | Жанры (TMDB) |
| `spookyapp_tmdb_blacklist` | Чёрный список ID |
| `spookyapp_cinemaprimiera_movies` | Фильмы в кинотеатрах |
| `spookyapp_cinema_scheldule` | Расписание сеансов |
| `spookyapp_football_fixtures` | Футбольные матчи |
| `spookyapp_football_tables` | Турнирные таблицы |
| `spookyapp_football_matchstats` | Статистика матчей |
| `spookyapp_game_info` | Игры (RAWG / IGDB) |

---

## Администрирование

После установки в меню **Компоненты** появится раздел **SpookyApp**. Интерфейс построен на ExtJS 3 и включает:

- грид со списком элементов;
- редакторы данных фильмов, сериалов, игр;
- вкладку управления спортивными данными (футбол);
- панель сервисов с возможностью ручного запуска обновлений.

---

## Сборка транспортного пакета

Для пересборки пакета из исходников выполните в командной строке:

```bash
php Extras/SpookyApp/_build/build.transport.php
```

Или откройте в браузере:

```
http://your-dev-site.local/Extras/SpookyApp/_build/build.transport.php?download=1
```

Готовый файл появится в `core/packages/spookyapp-3.x.x-pl.transport.zip`.

### Структура сборки

```
Extras/SpookyApp/_build/
├── build.transport.php   # Точка входа
├── build.php             # Класс SpookyAppPackage
├── config.inc.php        # Версия, флаги обновления
├── elements/
│   ├── chunks.php        # 7 чанков
│   ├── menus.php         # Пункт меню
│   ├── plugins.php       # 1 плагин
│   ├── settings.php      # 14 системных настроек
│   └── snippets.php      # 8 сниппетов
└── resolvers/
    ├── tables.php        # Создание/миграция таблиц БД
    └── symlinks.php      # Dev-симлинки (пропускается на проде)
```

> **Важно:** `update['settings'] = false` в `config.inc.php` означает, что при обновлении пакета существующие значения API-ключей **не перезаписываются**.

All resolvers and elements are in `_build` path. All files that begins not from `.` or `_` will be added automatically. 

If you will add a new type of element, you will need to add the method with that name into `build.php` script as well.

## Build and download

You can build package at any time by opening `http://dev.site.com/Extras/AnyOtherName/_build/build.php`

If you want to download built package - just add `?download=1` to the address.