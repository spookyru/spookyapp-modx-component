<!--
 * SpookyApp — Device Chunk Template
 *
 * ═══════════════════════════════════════════════════════════════
 * Шаблон для вывода информации об устройстве (телефон, планшет).
 *
 * Placeholders:
 *   [[+title]]          — Полное название (Brand + Model)
 *   [[+brand]]          — Бренд
 *   [[+model]]          — Модель
 *   [[+poster_path]]    — URL изображения
 *   [[+release_date]]   — Дата анонса/выхода
 *   [[+price]]          — Цена
 *   [[+os]]             — Операционная система
 *   [[+display_size]]   — Размер дисплея
 *   [[+display_type]]   — Тип дисплея
 *   [[+display_res]]    — Разрешение
 *   [[+chipset]]        — Процессор
 *   [[+ram]]            — Оперативная память
 *   [[+storage]]        — Встроенная память
 *   [[+camera_main]]    — Основная камера
 *   [[+camera_selfie]]  — Фронтальная камера
 *   [[+battery]]        — Батарея
 *   [[+charging]]       — Зарядка
 *   [[+dimensions]]     — Габариты
 *   [[+weight]]         — Вес
 *   [[+network]]        — Сеть (5G/4G)
 *   [[+nfc]]            — NFC
 *   [[+waterproof]]     — Влагозащита
 *   [[+colors]]         — Цвета
 *   [[+specs_extra]]    — Доп. спецификации (HTML)
 *   [[+source_url]]     — Ссылка на источник
 * ═══════════════════════════════════════════════════════════════
-->

<article class="spookyapp-card spookyapp-device" itemscope itemtype="https://schema.org/Product">

    <div class="spookyapp-card__body">

        <!-- ── Header ────────────────────────────────────────── -->
        <header class="spookyapp-card__header">

            [[+poster_path:notempty=`
            <div class="spookyapp-card__poster spookyapp-card__poster--device">
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

                [[+release_date:notempty=`
                <p class="spookyapp-card__release-date">
                    Анонс: [[+release_date]]
                </p>
                `]]

                [[+price:notempty=`
                <div class="spookyapp-card__price" itemprop="offers" itemscope itemtype="https://schema.org/Offer">
                    <span class="spookyapp-card__price-value" itemprop="price">[[+price]]</span>
                </div>
                `]]

                [[+colors:notempty=`
                <div class="spookyapp-card__colors">
                    Цвета: [[+colors]]
                </div>
                `]]
            </div>
        </header>

        <!-- ── Specifications Table ──────────────────────────── -->
        <section class="spookyapp-card__section spookyapp-card__specs">
            <h3 class="spookyapp-card__section-title">Характеристики</h3>

            <table class="spookyapp-card__specs-table" itemprop="additionalProperty">
                <tbody>
                    [[+display_size:notempty=`
                    <tr class="spookyapp-card__specs-row">
                        <th class="spookyapp-card__specs-label">Дисплей</th>
                        <td class="spookyapp-card__specs-value">
                            [[+display_size]]
                            [[+display_type:notempty=`, [[+display_type]]`]]
                            [[+display_res:notempty=`<br><small>[[+display_res]]</small>`]]
                        </td>
                    </tr>
                    `]]

                    [[+os:notempty=`
                    <tr class="spookyapp-card__specs-row">
                        <th class="spookyapp-card__specs-label">ОС</th>
                        <td class="spookyapp-card__specs-value" itemprop="operatingSystem">[[+os]]</td>
                    </tr>
                    `]]

                    [[+chipset:notempty=`
                    <tr class="spookyapp-card__specs-row">
                        <th class="spookyapp-card__specs-label">Процессор</th>
                        <td class="spookyapp-card__specs-value">[[+chipset]]</td>
                    </tr>
                    `]]

                    [[+ram:notempty=`
                    <tr class="spookyapp-card__specs-row">
                        <th class="spookyapp-card__specs-label">RAM</th>
                        <td class="spookyapp-card__specs-value">[[+ram]]</td>
                    </tr>
                    `]]

                    [[+storage:notempty=`
                    <tr class="spookyapp-card__specs-row">
                        <th class="spookyapp-card__specs-label">Память</th>
                        <td class="spookyapp-card__specs-value">[[+storage]]</td>
                    </tr>
                    `]]

                    [[+camera_main:notempty=`
                    <tr class="spookyapp-card__specs-row">
                        <th class="spookyapp-card__specs-label">Камера</th>
                        <td class="spookyapp-card__specs-value">
                            [[+camera_main]]
                            [[+camera_selfie:notempty=`<br><small>Фронтальная: [[+camera_selfie]]</small>`]]
                        </td>
                    </tr>
                    `]]

                    [[+battery:notempty=`
                    <tr class="spookyapp-card__specs-row">
                        <th class="spookyapp-card__specs-label">Батарея</th>
                        <td class="spookyapp-card__specs-value">
                            [[+battery]]
                            [[+charging:notempty=`<br><small>[[+charging]]</small>`]]
                        </td>
                    </tr>
                    `]]

                    [[+dimensions:notempty=`
                    <tr class="spookyapp-card__specs-row">
                        <th class="spookyapp-card__specs-label">Габариты</th>
                        <td class="spookyapp-card__specs-value">
                            [[+dimensions]]
                            [[+weight:notempty=`, [[+weight]]`]]
                        </td>
                    </tr>
                    `]]

                    [[+network:notempty=`
                    <tr class="spookyapp-card__specs-row">
                        <th class="spookyapp-card__specs-label">Сеть</th>
                        <td class="spookyapp-card__specs-value">[[+network]]</td>
                    </tr>
                    `]]

                    [[+nfc:notempty=`
                    <tr class="spookyapp-card__specs-row">
                        <th class="spookyapp-card__specs-label">NFC</th>
                        <td class="spookyapp-card__specs-value">[[+nfc]]</td>
                    </tr>
                    `]]

                    [[+waterproof:notempty=`
                    <tr class="spookyapp-card__specs-row">
                        <th class="spookyapp-card__specs-label">Защита</th>
                        <td class="spookyapp-card__specs-value">[[+waterproof]]</td>
                    </tr>
                    `]]
                </tbody>
            </table>
        </section>

        <!-- ── Extra Specs ───────────────────────────────────── -->
        [[+specs_extra:notempty=`
        <section class="spookyapp-card__section spookyapp-card__specs-extra">
            <h3 class="spookyapp-card__section-title">Дополнительно</h3>
            <div class="spookyapp-card__text">
                [[+specs_extra]]
            </div>
        </section>
        `]]

        <!-- ── Links ─────────────────────────────────────────── -->
        <footer class="spookyapp-card__footer">
            <div class="spookyapp-card__links">
                [[+source_url:notempty=`
                <a href="[[+source_url]]"
                   target="_blank" rel="noopener noreferrer"
                   class="spookyapp-card__link spookyapp-card__link--homepage">
                    <span class="spookyapp-card__link-icon">📱</span> Подробнее
                </a>
                `]]
            </div>
        </footer>

    </div>
</article>