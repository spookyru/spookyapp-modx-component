<!--
 * SpookyApp — Game Chunk Template
 *
 * ═══════════════════════════════════════════════════════════════
 * Шаблон для вывода информации об игре.
 *
 * Placeholders:
 *   [[+title]]            — Название
 *   [[+description]]      — Описание
 *   [[+poster_path]]      — URL постера
 *   [[+backdrop_path]]    — URL фона / скриншот
 *   [[+released]]         — Дата выхода
 *   [[+platforms]]        — Платформы (через запятую)
 *   [[+genres]]           — Жанры
 *   [[+rating]]           — Рейтинг (0-5 от RAWG)
 *   [[+metacritic]]       — Metacritic оценка
 *   [[+playtime]]         — Среднее время (часы)
 *   [[+esrb_rating]]      — ESRB рейтинг
 *   [[+developers]]       — Разработчики
 *   [[+publishers]]       — Издатели
 *   [[+website]]          — Официальный сайт
 *   [[+steam_url]]        — Ссылка на Steam
 *   [[+epic_url]]         — Ссылка на Epic Games
 *   [[+rawg_id]]          — RAWG ID
 *   [[+screenshots]]      — HTML скриншотов
 *   [[+requirements_min]] — Мин. требования (HTML)
 *   [[+requirements_rec]] — Рек. требования (HTML)
 *   [[+audio_url]]        — URL аудио
 * ═══════════════════════════════════════════════════════════════
-->

<article class="spookyapp-card spookyapp-game" itemscope itemtype="https://schema.org/VideoGame">

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

                [[+released:notempty=`
                <p class="spookyapp-card__original-title">
                    <span class="spookyapp-card__year">[[+released]]</span>
                </p>
                `]]

                <!-- ── Ratings Row ───────────────────────────── -->
                <div class="spookyapp-card__ratings-row">
                    [[+rating:notempty=`
                    <div class="spookyapp-card__rating">
                        <span class="spookyapp-card__rating-star">★</span>
                        <span class="spookyapp-card__rating-value" itemprop="aggregateRating"
                              itemscope itemtype="https://schema.org/AggregateRating">
                            <span itemprop="ratingValue">[[+rating]]</span><span class="spookyapp-card__rating-max">/5</span>
                        </span>
                    </div>
                    `]]

                    [[+metacritic:notempty=`
                    <div class="spookyapp-card__metacritic">
                        <span class="spookyapp-card__metacritic-label">Metacritic:</span>
                        <span class="spookyapp-card__metacritic-value">[[+metacritic]]</span>
                    </div>
                    `]]
                </div>

                <!-- ── Platforms ──────────────────────────────── -->
                [[+platforms:notempty=`
                <div class="spookyapp-card__platforms">
                    [[+platforms]]
                </div>
                `]]
            </div>
        </header>

        <!-- ── Meta Info ─────────────────────────────────────── -->
        <div class="spookyapp-card__meta">
            <ul class="spookyapp-card__meta-list">
                [[+released:notempty=`
                <li class="spookyapp-card__meta-item">
                    <span class="spookyapp-card__meta-label">Дата выхода:</span>
                    <span class="spookyapp-card__meta-value" itemprop="datePublished">[[+released]]</span>
                </li>
                `]]

                [[+genres:notempty=`
                <li class="spookyapp-card__meta-item">
                    <span class="spookyapp-card__meta-label">Жанр:</span>
                    <span class="spookyapp-card__meta-value" itemprop="genre">[[+genres]]</span>
                </li>
                `]]

                [[+developers:notempty=`
                <li class="spookyapp-card__meta-item">
                    <span class="spookyapp-card__meta-label">Разработчик:</span>
                    <span class="spookyapp-card__meta-value">[[+developers]]</span>
                </li>
                `]]

                [[+publishers:notempty=`
                <li class="spookyapp-card__meta-item">
                    <span class="spookyapp-card__meta-label">Издатель:</span>
                    <span class="spookyapp-card__meta-value">[[+publishers]]</span>
                </li>
                `]]

                [[+playtime:notempty=`
                <li class="spookyapp-card__meta-item">
                    <span class="spookyapp-card__meta-label">Среднее время:</span>
                    <span class="spookyapp-card__meta-value">~[[+playtime]] ч.</span>
                </li>
                `]]

                [[+esrb_rating:notempty=`
                <li class="spookyapp-card__meta-item">
                    <span class="spookyapp-card__meta-label">ESRB:</span>
                    <span class="spookyapp-card__meta-value">[[+esrb_rating]]</span>
                </li>
                `]]
            </ul>
        </div>

        <!-- ── Description ───────────────────────────────────── -->
        [[+description:notempty=`
        <section class="spookyapp-card__section spookyapp-card__overview">
            <h3 class="spookyapp-card__section-title">Описание</h3>
            <div class="spookyapp-card__text" itemprop="description">
                [[+description]]
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

        <!-- ── Screenshots ───────────────────────────────────── -->
        [[+screenshots:notempty=`
        <section class="spookyapp-card__section spookyapp-card__screenshots">
            <h3 class="spookyapp-card__section-title">Скриншоты</h3>
            <div class="spookyapp-card__screenshots-grid">
                [[+screenshots]]
            </div>
        </section>
        `]]

        <!-- ── System Requirements ───────────────────────────── -->
        [[+requirements_min:notempty=`
        <section class="spookyapp-card__section spookyapp-card__requirements">
            <h3 class="spookyapp-card__section-title">Системные требования</h3>
            <div class="spookyapp-card__requirements-grid">

                <div class="spookyapp-card__requirements-col">
                    <h4 class="spookyapp-card__requirements-heading">Минимальные</h4>
                    <div class="spookyapp-card__requirements-body">
                        [[+requirements_min]]
                    </div>
                </div>

                [[+requirements_rec:notempty=`
                <div class="spookyapp-card__requirements-col">
                    <h4 class="spookyapp-card__requirements-heading">Рекомендуемые</h4>
                    <div class="spookyapp-card__requirements-body">
                        [[+requirements_rec]]
                    </div>
                </div>
                `]]

            </div>
        </section>
        `]]

        <!-- ── Links ─────────────────────────────────────────── -->
        <footer class="spookyapp-card__footer">
            <div class="spookyapp-card__links">
                [[+steam_url:notempty=`
                <a href="[[+steam_url]]"
                   target="_blank" rel="noopener noreferrer"
                   class="spookyapp-card__link spookyapp-card__link--steam">
                    <span class="spookyapp-card__link-icon">🎮</span> Steam
                </a>
                `]]

                [[+epic_url:notempty=`
                <a href="[[+epic_url]]"
                   target="_blank" rel="noopener noreferrer"
                   class="spookyapp-card__link spookyapp-card__link--epic">
                    <span class="spookyapp-card__link-icon">🎮</span> Epic Games
                </a>
                `]]

                [[+website:notempty=`
                <a href="[[+website]]"
                   target="_blank" rel="noopener noreferrer"
                   class="spookyapp-card__link spookyapp-card__link--homepage">
                    <span class="spookyapp-card__link-icon">🌐</span> Сайт
                </a>
                `]]

                [[+rawg_id:notempty=`
                <a href="https://rawg.io/games/[[+rawg_id]]"
                   target="_blank" rel="noopener noreferrer"
                   class="spookyapp-card__link spookyapp-card__link--rawg">
                    <span class="spookyapp-card__link-icon">🕹️</span> RAWG
                </a>
                `]]
            </div>
        </footer>

    </div>
</article>