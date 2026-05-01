<!--
 * SpookyApp — Person Chunk Template
 *
 * ═══════════════════════════════════════════════════════════════
 * Шаблон для вывода информации о персоне (актёр, режиссёр).
 *
 * Placeholders:
 *   [[+name]]                    — Имя
 *   [[+also_known_as]]           — Другие имена
 *   [[+poster_path]]             — Фото
 *   [[+birthday]]                — Дата рождения
 *   [[+age]]                     — Возраст
 *   [[+deathday]]                — Дата смерти
 *   [[+place_of_birth]]          — Место рождения
 *   [[+known_for_department]]    — Известен как
 *   [[+biography]]               — Биография
 *   [[+popularity]]              — Популярность
 *   [[+filmography]]             — HTML фильмографии (топ 10)
 *   [[+imdb_id]]                 — IMDb ID
 *   [[+tmdb_id]]                 — TMDB ID
 *   [[+wikipedia_url]]           — Ссылка на Wikipedia
 *   [[+homepage]]                — Личный сайт
 *   [[+audio_url]]               — URL аудио биографии
 * ═══════════════════════════════════════════════════════════════
-->

<article class="spookyapp-card spookyapp-person" itemscope itemtype="https://schema.org/Person">

    <div class="spookyapp-card__body">

        <!-- ── Header ────────────────────────────────────────── -->
        <header class="spookyapp-card__header spookyapp-card__header--person">

            [[+poster_path:notempty=`
            <div class="spookyapp-card__poster spookyapp-card__poster--person">
                <img src="[[+poster_path]]"
                     alt="[[+name]]"
                     loading="lazy"
                     itemprop="image"
                     class="spookyapp-card__poster-img" />
            </div>
            `]]

            <div class="spookyapp-card__title-block">
                <h2 class="spookyapp-card__title" itemprop="name">
                    [[+name]]
                </h2>

                [[+also_known_as:notempty=`
                <p class="spookyapp-card__original-title" itemprop="alternateName">
                    [[+also_known_as]]
                </p>
                `]]

                [[+known_for_department:notempty=`
                <p class="spookyapp-card__tagline" itemprop="jobTitle">
                    [[+known_for_department]]
                </p>
                `]]
            </div>
        </header>

        <!-- ── Personal Info ─────────────────────────────────── -->
        <div class="spookyapp-card__meta">
            <ul class="spookyapp-card__meta-list">
                [[+birthday:notempty=`
                <li class="spookyapp-card__meta-item">
                    <span class="spookyapp-card__meta-label">Дата рождения:</span>
                    <span class="spookyapp-card__meta-value" itemprop="birthDate">
                        [[+birthday]]
                        [[+age:notempty=`<span class="spookyapp-card__age">([[+age]] лет)</span>`]]
                    </span>
                </li>
                `]]

                [[+deathday:notempty=`
                <li class="spookyapp-card__meta-item">
                    <span class="spookyapp-card__meta-label">Дата смерти:</span>
                    <span class="spookyapp-card__meta-value" itemprop="deathDate">[[+deathday]]</span>
                </li>
                `]]

                [[+place_of_birth:notempty=`
                <li class="spookyapp-card__meta-item">
                    <span class="spookyapp-card__meta-label">Место рождения:</span>
                    <span class="spookyapp-card__meta-value" itemprop="birthPlace">[[+place_of_birth]]</span>
                </li>
                `]]

                [[+popularity:notempty=`
                <li class="spookyapp-card__meta-item">
                    <span class="spookyapp-card__meta-label">Популярность:</span>
                    <span class="spookyapp-card__meta-value">[[+popularity]]</span>
                </li>
                `]]
            </ul>
        </div>

        <!-- ── Biography ─────────────────────────────────────── -->
        [[+biography:notempty=`
        <section class="spookyapp-card__section spookyapp-card__overview">
            <h3 class="spookyapp-card__section-title">Биография</h3>
            <div class="spookyapp-card__text spookyapp-card__text--expandable" itemprop="description">
                [[+biography]]
            </div>
        </section>
        `]]

        <!-- ── Audio Player ──────────────────────────────────── -->
        [[+audio_url:notempty=`
        <section class="spookyapp-card__section spookyapp-card__audio">
            <h3 class="spookyapp-card__section-title">🎧 Озвучка биографии</h3>
            <audio controls preload="none" class="spookyapp-card__audio-player">
                <source src="[[+audio_url]]" type="audio/ogg" />
                <source src="[[+audio_url]]" type="audio/mpeg" />
            </audio>
        </section>
        `]]

        <!-- ── Filmography ───────────────────────────────────── -->
        [[+filmography:notempty=`
        <section class="spookyapp-card__section spookyapp-card__filmography">
            <h3 class="spookyapp-card__section-title">Фильмография</h3>
            <div class="spookyapp-card__filmography-list">
                [[+filmography]]
            </div>
        </section>
        `]]

        <!-- ── Links ─────────────────────────────────────────── -->
        <footer class="spookyapp-card__footer">
            <div class="spookyapp-card__links">
                [[+imdb_id:notempty=`
                <a href="https://www.imdb.com/name/[[+imdb_id]]/"
                   target="_blank" rel="noopener noreferrer"
                   class="spookyapp-card__link spookyapp-card__link--imdb">
                    <span class="spookyapp-card__link-icon">🎬</span> IMDb
                </a>
                `]]

                [[+tmdb_id:notempty=`
                <a href="https://www.themoviedb.org/person/[[+tmdb_id]]"
                   target="_blank" rel="noopener noreferrer"
                   class="spookyapp-card__link spookyapp-card__link--tmdb">
                    <span class="spookyapp-card__link-icon">🎞️</span> TMDB
                </a>
                `]]

                [[+wikipedia_url:notempty=`
                <a href="[[+wikipedia_url]]"
                   target="_blank" rel="noopener noreferrer"
                   class="spookyapp-card__link spookyapp-card__link--wiki">
                    <span class="spookyapp-card__link-icon">📖</span> Wikipedia
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