<!--
 * SpookyApp — Product Chunk Template
 *
 * ═══════════════════════════════════════════════════════════════
 * Шаблон для вывода информации о товаре.
 *
 * Placeholders:
 *   [[+title]]          — Название
 *   [[+description]]    — Описание
 *   [[+poster_path]]    — Изображение
 *   [[+price]]          — Цена
 *   [[+currency]]       — Валюта (RUB, USD, etc.)
 *   [[+old_price]]      — Старая цена (перечёркнутая)
 *   [[+brand]]          — Бренд
 *   [[+category]]       — Категория
 *   [[+rating]]         — Рейтинг (0-5)
 *   [[+reviews_count]]  — Количество отзывов
 *   [[+in_stock]]       — В наличии (1/0)
 *   [[+offers]]         — HTML с партнёрскими офферами
 *   [[+features]]       — HTML со списком features
 *   [[+source_url]]     — URL источника
 * ═══════════════════════════════════════════════════════════════
-->

<article class="spookyapp-card spookyapp-product" itemscope itemtype="https://schema.org/Product">

    <div class="spookyapp-card__body">

        <!-- ── Header ────────────────────────────────────────── -->
        <header class="spookyapp-card__header">

            [[+poster_path:notempty=`
            <div class="spookyapp-card__poster spookyapp-card__poster--product">
                <img src="[[+poster_path]]"
                     alt="[[+title]]"
                     loading="lazy"
                     itemprop="image"
                     class="spookyapp-card__poster-img" />
            </div>
            `]]

            <div class="spookyapp-card__title-block">

                [[+brand:notempty=`
                <p class="spookyapp-card__brand" itemprop="brand" itemscope itemtype="https://schema.org/Brand">
                    <span itemprop="name">[[+brand]]</span>
                </p>
                `]]

                <h2 class="spookyapp-card__title" itemprop="name">
                    [[+title]]
                </h2>

                [[+category:notempty=`
                <p class="spookyapp-card__category">
                    [[+category]]
                </p>
                `]]

                <!-- ── Price Block ───────────────────────────── -->
                <div class="spookyapp-card__price-block" itemprop="offers" itemscope itemtype="https://schema.org/Offer">
                    [[+price:notempty=`
                    <span class="spookyapp-card__price-value" itemprop="price" content="[[+price]]">
                        [[+price]]
                    </span>
                    [[+currency:notempty=`
                    <span class="spookyapp-card__price-currency" itemprop="priceCurrency" content="[[+currency]]">
                        [[+currency]]
                    </span>
                    `]]
                    `]]

                    [[+old_price:notempty=`
                    <span class="spookyapp-card__price-old">
                        [[+old_price]]
                    </span>
                    `]]

                    [[+in_stock:is=`1`:then=`
                    <span class="spookyapp-card__stock spookyapp-card__stock--available" itemprop="availability" content="https://schema.org/InStock">
                        ✓ В наличии
                    </span>
                    `:else=`
                    <span class="spookyapp-card__stock spookyapp-card__stock--unavailable" itemprop="availability" content="https://schema.org/OutOfStock">
                        ✗ Нет в наличии
                    </span>
                    `]]
                </div>

                <!-- ── Rating ────────────────────────────────── -->
                [[+rating:notempty=`
                <div class="spookyapp-card__rating" itemprop="aggregateRating" itemscope itemtype="https://schema.org/AggregateRating">
                    <span class="spookyapp-card__rating-stars">
                        [[+rating:le=`0.5`:then=`☆☆☆☆☆`]]
                        [[+rating:gt=`0.5`:and:le=`1.5`:then=`★☆☆☆☆`]]
                        [[+rating:gt=`1.5`:and:le=`2.5`:then=`★★☆☆☆`]]
                        [[+rating:gt=`2.5`:and:le=`3.5`:then=`★★★☆☆`]]
                        [[+rating:gt=`3.5`:and:le=`4.5`:then=`★★★★☆`]]
                        [[+rating:gt=`4.5`:then=`★★★★★`]]
                    </span>
                    <span class="spookyapp-card__rating-value">
                        <span itemprop="ratingValue">[[+rating]]</span>/5
                    </span>
                    [[+reviews_count:notempty=`
                    <span class="spookyapp-card__reviews-count">
                        (<span itemprop="reviewCount">[[+reviews_count]]</span> отзывов)
                    </span>
                    `]]
                </div>
                `]]
            </div>
        </header>

        <!-- ── Description ───────────────────────────────────── -->
        [[+description:notempty=`
        <section class="spookyapp-card__section spookyapp-card__overview">
            <h3 class="spookyapp-card__section-title">Описание</h3>
            <div class="spookyapp-card__text" itemprop="description">
                [[+description]]
            </div>
        </section>
        `]]

        <!-- ── Features ──────────────────────────────────────── -->
        [[+features:notempty=`
        <section class="spookyapp-card__section spookyapp-card__features">
            <h3 class="spookyapp-card__section-title">Особенности</h3>
            <div class="spookyapp-card__features-list">
                [[+features]]
            </div>
        </section>
        `]]

        <!-- ── Offers ────────────────────────────────────────── -->
        [[+offers:notempty=`
        <section class="spookyapp-card__section spookyapp-card__offers">
            <h3 class="spookyapp-card__section-title">Где купить</h3>
            <div class="spookyapp-card__offers-grid">
                [[+offers]]
            </div>
        </section>
        `]]

        <!-- ── Links ─────────────────────────────────────────── -->
        <footer class="spookyapp-card__footer">
            <div class="spookyapp-card__links">
                [[+source_url:notempty=`
                <a href="[[+source_url]]"
                   target="_blank" rel="noopener noreferrer nofollow"
                   class="spookyapp-card__link spookyapp-card__link--buy">
                    <span class="spookyapp-card__link-icon">🛒</span> Перейти в магазин
                </a>
                `]]
            </div>
        </footer>

    </div>
</article>