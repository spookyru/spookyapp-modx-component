<!--
 * SpookyApp — TV Show Chunk Template
 *
 * ═══════════════════════════════════════════════════════════════
 * Шаблон для вывода информации о TV-сериале.
 *
 * Placeholders:
 *   [[+title]]               — Название
 *   [[+original_title]]      — Оригинальное название
 *   [[+tagline]]             — Слоган
 *   [[+overview]]            — Описание
 *   [[+poster_path]]         — URL постера
 *   [[+backdrop_path]]       — URL фона
 *   [[+first_air_date]]      — Дата первой серии
 *   [[+last_air_date]]       — Дата последней серии
 *   [[+number_of_seasons]]   — Количество сезонов
 *   [[+number_of_episodes]]  — Количество серий
 *   [[+episode_run_time]]    — Длительность серии (мин)
 *   [[+genres]]              — Жанры
 *   [[+rating]]              — Рейтинг
 *   [[+vote_count]]          — Голоса
 *   [[+status]]              — Статус (Returning Series / Ended / Canceled)
 *   [[+networks]]            — Телеканалы/платформы
 *   [[+cast]]                — JSON актёров
 *   [[+created_by]]          — Создатели
 *   [[+seasons]]             — HTML сезонов
 *   [[+imdb_id]]             — IMDb ID
 *   [[+tmdb_id]]             — TMDB ID
 *   [[+homepage]]            — Сайт
 *   [[+audio_url]]           — URL аудио
 * ═══════════════════════════════════════════════════════════════
-->

<article class="spookyapp-card spookyapp-tvshow" itemscope itemtype="https://schema.org/TVSeries">

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

        <!-- ── Header ────────────────────────────────────────── -->
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
                    [[+first_air_date:notempty=`
                    <span class="spookyapp-card__year">([[+first_air_date:date=`%Y`]])</span>
                    `]]
                </p>
                `]]

                [[+tagline:notempty=`
                <p class="spookyapp-card__tagline">«[[+tagline]]»</p>
                `]]

                <!-- ── Status Badge ──────────────────────────── -->
                [[+status:notempty=`
                <div class="spookyapp-card__status-badge spookyapp-card__status-badge--[[+status:lcase:replace=` ===-`]]">
                    [[+status]]
                </div>
                `]]

                [[+rating:notempty=`
                <div class="spookyapp-card__rating">
                    <span class="spookyapp-card__rating-star">★</span>
                    <span class="spookyapp-card__rating-value" itemprop="aggregateRating"
                          itemscope itemtype="https://schema.org/AggregateRating">
                        <span itemprop="ratingValue">[[+rating]]</span><span class="spookyapp-card__rating-max">/10</span>
                        [[+vote_count:notempty=`
                        <span class="spookyapp-card__vote-count">(<span itemprop="ratingCount">[[+vote_count]]</span>)</span>
                        `]]
                    </span>
                </div>
                `]]
            </div>
        </header>

        <!-- ── Meta Info ─────────────────────────────────────── -->
        <div class="spookyapp-card__meta">
            <ul class="spookyapp-card__meta-list">
                [[+first_air_date:notempty=`
                <li class="spookyapp-card__meta-item">
                    <span class="spookyapp-card__meta-label">Первая серия:</span>
                    <span class="spookyapp-card__meta-value">[[+first_air_date]]</span>
                </li>
                `]]

                [[+last_air_date:notempty=`
                <li class="spookyapp-card__meta-item">
                    <span class="spookyapp-card__meta-label">Последняя серия:</span>
                    <span class="spookyapp-card__meta-value">[[+last_air_date]]</span>
                </li>
                `]]

                [[+number_of_seasons:notempty=`
                <li class="spookyapp-card__meta-item">
                    <span class="spookyapp-card__meta-label">Сезонов:</span>
                    <span class="spookyapp-card__meta-value" itemprop="numberOfSeasons">[[+number_of_seasons]]</span>
                </li>
                `]]

                [[+number_of_episodes:notempty=`
                <li class="spookyapp-card__meta-item">
                    <span class="spookyapp-card__meta-label">Серий:</span>
                    <span class="spookyapp-card__meta-value" itemprop="numberOfEpisodes">[[+number_of_episodes]]</span>
                </li>
                `]]

                [[+episode_run_time:notempty=`
                <li class="spookyapp-card__meta-item">
                    <span class="spookyapp-card__meta-label">Длительность серии:</span>
                    <span class="spookyapp-card__meta-value">~[[+episode_run_time]] мин.</span>
                </li>
                `]]

                [[+genres:notempty=`
                <li class="spookyapp-card__meta-item">
                    <span class="spookyapp-card__meta-label">Жанр:</span>
                    <span class="spookyapp-card__meta-value" itemprop="genre">[[+genres]]</span>
                </li>
                `]]

                [[+networks:notempty=`
                <li class="spookyapp-card__meta-item">
                    <span class="spookyapp-card__meta-label">Платформа:</span>
                    <span class="spookyapp-card__meta-value">[[+networks]]</span>
                </li>
                `]]

                [[+created_by:notempty=`
                <li class="spookyapp-card__meta-item">
                    <span class="spookyapp-card__meta-label">Создатели:</span>
                    <span class="spookyapp-card__meta-value" itemprop="creator">[[+created_by]]</span>
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
            </audio>
        </section>
        `]]

        <!-- ── Seasons ───────────────────────────────────────── -->
        [[+seasons:notempty=`
        <section class="spookyapp-card__section spookyapp-card__seasons">
            <h3 class="spookyapp-card__section-title">Сезоны</h3>
            <div class="spookyapp-card__seasons-grid">
                [[+seasons]]
            </div>
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
                   class="spookyapp-card__link spookyapp-card__link--imdb">
                    <span class="spookyapp-card__link-icon">🎬</span> IMDb
                </a>
                `]]

                [[+tmdb_id:notempty=`
                <a href="https://www.themoviedb.org/tv/[[+tmdb_id]]"
                   target="_blank" rel="noopener noreferrer"
                   class="spookyapp-card__link spookyapp-card__link--tmdb">
                    <span class="spookyapp-card__link-icon">🎞️</span> TMDB
                </a>
                `]]

                [[+homepage:notempty=`
                <a href="[[+homepage]]"
                   target="_blank" rel="noopener noreferrer"
                   class="spookyapp-card__link spookyapp-card__link--homepage">
                    <span class="spookyapp-card__link-icon">🌐</span> Сайт
                </a>
                `]]
            </div>
        </footer>

    </div>
</article>