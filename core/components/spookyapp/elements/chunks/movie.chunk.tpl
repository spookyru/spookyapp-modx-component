<!--
 * SpookyApp — Movie Chunk Template
 *
 * ═══════════════════════════════════════════════════════════════
 * Шаблон для вывода информации о фильме.
 *
 * Placeholders:
 *   [[+title]]           — Название
 *   [[+original_title]]  — Оригинальное название
 *   [[+tagline]]         — Слоган
 *   [[+overview]]        — Описание
 *   [[+poster_path]]     — URL постера
 *   [[+backdrop_path]]   — URL фона
 *   [[+release_date]]    — Дата выхода (YYYY-MM-DD)
 *   [[+runtime]]         — Длительность (мин)
 *   [[+genres]]          — Жанры (через запятую)
 *   [[+rating]]          — Рейтинг (0-10)
 *   [[+vote_count]]      — Количество голосов
 *   [[+budget]]          — Бюджет
 *   [[+revenue]]         — Сборы
 *   [[+status]]          — Статус
 *   [[+imdb_id]]         — IMDb ID
 *   [[+tmdb_id]]         — TMDB ID
 *   [[+homepage]]        — Официальный сайт
 *   [[+cast]]            — JSON массив актёров
 *   [[+crew]]            — JSON массив съёмочной группы
 *   [[+director]]        — Режиссёр
 *   [[+writer]]          — Сценарист
 *   [[+audio_url]]       — URL аудио озвучки
 *   [[+countries]]       — Страны (через запятую)
 * ═══════════════════════════════════════════════════════════════
-->

<article class="spookyapp-card spookyapp-movie" itemscope itemtype="https://schema.org/Movie">

    <!-- ── Backdrop ──────────────────────────────────────────── -->
    [[+backdrop_path:notempty=`
    <div class="spookyapp-card__backdrop">
        <img src="[[+backdrop_path]]"
             alt="[[+title]]"
             loading="lazy"
             class="spookyapp-card__backdrop-img" />
        <div class="spookyapp-card__backdrop-overlay"></div>
    </div>
    `]]

    <div class="spookyapp-card__body">

        <!-- ── Header: Poster + Title ────────────────────────── -->
        <header class="spookyapp-card__header">

            [[+poster_path:notempty=`
            <div class="spookyapp-card__poster">
                <img src="[[+poster_path]]"
                     alt="[[+title]]"
                     loading="lazy"
                     itemprop="image"
                     class="spookyapp-card__poster-img" />
            </div>
            `]]

            <div class="spookyapp-card__title-block">
                <h2 class="spookyapp-card__title" itemprop="name">
                    [[+title]]
                </h2>

                [[+original_title:notempty=`
                <p class="spookyapp-card__original-title">
                    [[+original_title]]
                    [[+release_date:notempty=`
                    <span class="spookyapp-card__year">([[+release_date:date=`%Y`]])</span>
                    `]]
                </p>
                `]]

                [[+tagline:notempty=`
                <p class="spookyapp-card__tagline" itemprop="description">
                    «[[+tagline]]»
                </p>
                `]]

                <!-- ── Rating Badge ──────────────────────────── -->
                [[+rating:notempty=`
                <div class="spookyapp-card__rating">
                    <span class="spookyapp-card__rating-star">★</span>
                    <span class="spookyapp-card__rating-value" itemprop="aggregateRating"
                          itemscope itemtype="https://schema.org/AggregateRating">
                        <span itemprop="ratingValue">[[+rating]]</span><span class="spookyapp-card__rating-max">/10</span>
                        [[+vote_count:notempty=`
                        <span class="spookyapp-card__vote-count">
                            (<span itemprop="ratingCount">[[+vote_count]]</span> голосов)
                        </span>
                        `]]
                    </span>
                </div>
                `]]
            </div>
        </header>

        <!-- ── Meta Info ─────────────────────────────────────── -->
        <div class="spookyapp-card__meta">
            <ul class="spookyapp-card__meta-list">
                [[+release_date:notempty=`
                <li class="spookyapp-card__meta-item">
                    <span class="spookyapp-card__meta-label">Дата выхода:</span>
                    <span class="spookyapp-card__meta-value" itemprop="datePublished">[[+release_date]]</span>
                </li>
                `]]

                [[+runtime:notempty=`
                <li class="spookyapp-card__meta-item">
                    <span class="spookyapp-card__meta-label">Длительность:</span>
                    <span class="spookyapp-card__meta-value" itemprop="duration">[[+runtime]] мин.</span>
                </li>
                `]]

                [[+genres:notempty=`
                <li class="spookyapp-card__meta-item">
                    <span class="spookyapp-card__meta-label">Жанр:</span>
                    <span class="spookyapp-card__meta-value" itemprop="genre">[[+genres]]</span>
                </li>
                `]]

                [[+countries:notempty=`
                <li class="spookyapp-card__meta-item">
                    <span class="spookyapp-card__meta-label">Страна:</span>
                    <span class="spookyapp-card__meta-value">[[+countries]]</span>
                </li>
                `]]

                [[+director:notempty=`
                <li class="spookyapp-card__meta-item">
                    <span class="spookyapp-card__meta-label">Режиссёр:</span>
                    <span class="spookyapp-card__meta-value" itemprop="director">[[+director]]</span>
                </li>
                `]]

                [[+writer:notempty=`
                <li class="spookyapp-card__meta-item">
                    <span class="spookyapp-card__meta-label">Сценарист:</span>
                    <span class="spookyapp-card__meta-value">[[+writer]]</span>
                </li>
                `]]

                [[+budget:notempty=`
                <li class="spookyapp-card__meta-item">
                    <span class="spookyapp-card__meta-label">Бюджет:</span>
                    <span class="spookyapp-card__meta-value">$[[+budget]]</span>
                </li>
                `]]

                [[+revenue:notempty=`
                <li class="spookyapp-card__meta-item">
                    <span class="spookyapp-card__meta-label">Сборы:</span>
                    <span class="spookyapp-card__meta-value">$[[+revenue]]</span>
                </li>
                `]]

                [[+status:notempty=`
                <li class="spookyapp-card__meta-item">
                    <span class="spookyapp-card__meta-label">Статус:</span>
                    <span class="spookyapp-card__meta-value">[[+status]]</span>
                </li>
                `]]
            </ul>
        </div>

        <!-- ── Overview ──────────────────────────────────────── -->
        [[+overview:notempty=`
        <section class="spookyapp-card__section spookyapp-card__overview">
            <h3 class="spookyapp-card__section-title">Описание</h3>
            <div class="spookyapp-card__text" itemprop="description">
                [[+overview]]
            </div>
        </section>
        `]]

        <!-- ── Audio Player ──────────────────────────────────── -->
        [[+audio_url:notempty=`
        <section class="spookyapp-card__section spookyapp-card__audio">
            <h3 class="spookyapp-card__section-title">🎧 Озвучка</h3>
            <audio controls preload="none" class="spookyapp-card__audio-player">
                <source src="[[+audio_url]]" type="audio/ogg" />
                <source src="[[+audio_url]]" type="audio/mpeg" />
                Ваш браузер не поддерживает аудио.
            </audio>
        </section>
        `]]

        <!-- ── Cast ──────────────────────────────────────────── -->
        [[+cast:notempty=`
        <section class="spookyapp-card__section spookyapp-card__cast">
            <h3 class="spookyapp-card__section-title">Актёры</h3>
            <div class="spookyapp-card__cast-grid">
                [[+cast]]
            </div>
        </section>
        `]]

        <!-- ── Links ─────────────────────────────────────────── -->
        <footer class="spookyapp-card__footer">
            <div class="spookyapp-card__links">
                [[+imdb_id:notempty=`
                <a href="https://www.imdb.com/title/[[+imdb_id]]/"
                   target="_blank" rel="noopener noreferrer"
                   class="spookyapp-card__link spookyapp-card__link--imdb"
                   title="IMDb">
                    <span class="spookyapp-card__link-icon">🎬</span> IMDb
                </a>
                `]]

                [[+tmdb_id:notempty=`
                <a href="https://www.themoviedb.org/movie/[[+tmdb_id]]"
                   target="_blank" rel="noopener noreferrer"
                   class="spookyapp-card__link spookyapp-card__link--tmdb"
                   title="TMDB">
                    <span class="spookyapp-card__link-icon">🎞️</span> TMDB
                </a>
                `]]

                [[+homepage:notempty=`
                <a href="[[+homepage]]"
                   target="_blank" rel="noopener noreferrer"
                   class="spookyapp-card__link spookyapp-card__link--homepage"
                   title="Official Website">
                    <span class="spookyapp-card__link-icon">🌐</span> Сайт
                </a>
                `]]
            </div>
        </footer>

    </div>
</article>