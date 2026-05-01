/**
 * SpookyApp — главный JS-файл компонента.
 *
 * ═══════════════════════════════════════════════════════════════
 * Загружается ПЕРВЫМ. Объявляет namespace, конфигурацию,
 * утилиты и систему лексиконов.
 * Все остальные JS-файлы (panel, grid, window) зависят от него.
 * ═══════════════════════════════════════════════════════════════
 *
 * Использование:
 *   SpookyApp.config.connector_url  — URL коннектора
 *   SpookyApp.util.formatScore(85)  — цветной badge со score
 *   SpookyApp.lexicon.get('key')    — получение перевода
 *
 * @package SpookyApp
 * @subpackage JS\Manager
 */

// ╔═════════════════════════════════════════════════════════════╗
// ║  1. Namespace: ExtJS Component                             ║
// ╚═════════════════════════════════════════════════════════════╝

/**
 * Конструктор компонента SpookyApp.
 *
 * Регистрируется как ExtJS-компонент для CMP (Custom Manager Page).
 * Предоставляет контейнеры для page, window, grid, panel, combo, view.
 *
 * @class SpookyApp
 * @extends Ext.Component
 * @param {Object} [config={}] Конфигурация компонента
 */
var SpookyApp = function (config) {
    config = config || {};
    SpookyApp.superclass.constructor.call(this, config);
};

Ext.extend(SpookyApp, Ext.Component, {

    /** @type {Object} Контейнер для grid-классов */
    grid: {},
    /** @type {Object} Контейнер для tree-классов */
    tree: {},
    /** @type {Object} Контейнер для panel-классов */
  panel: {},
  /** @type {Object} Контейнер для page-классов */
    page: {},
    /** @type {Object} Контейнер для form-классов */
    form: {},
    /** @type {Object} Контейнер для window-классов */
    window: {},
    /** @type {Object} Контейнер для combo-классов */
    combo: {},
    /** @type {Object} Контейнер для view-классов */
    view: {},
    /** @type {Object} Контейнер для конфигурации */
    config: {},
    /** @type {Object} Контейнер для утилит */
    utils: {}
});

Ext.reg('spookyapp', SpookyApp);

// Создаём singleton-экземпляр
SpookyApp = new SpookyApp();

// ╔═════════════════════════════════════════════════════════════╗
// ║  2. Config: URL-ы, версия, параметры                       ║
// ╚═════════════════════════════════════════════════════════════╝

/**
 * Конфигурация компонента.
 *
 * Значения connector_url и assets_url перезаписываются
 * из PHP-контроллера при загрузке CMP:
 *
 * ```php
 * $this->addJavascript('...spookyapp.js');
 * $this->addHtml('<script>Ext.applyIf(SpookyApp.config, '
 *     . json_encode($config) . ');</script>');
 * ```
 *
 * @type {Object}
 * @property {string} connector_url — URL коннектора для AJAX-запросов
 * @property {string} assets_url    — URL до assets компонента
 * @property {string} version       — Версия компонента
 */
SpookyApp.config = SpookyApp.config || {};

Ext.applyIf(SpookyApp.config, {
    connector_url: '/assets/components/spookyapp/connector.php',
    assets_url: '/assets/components/spookyapp/',
    version: '1.0.0'
});

// ╔═════════════════════════════════════════════════════════════╗
// ║  3. Utility: вспомогательные функции                       ║
// ╚═════════════════════════════════════════════════════════════╝

/**
 * Набор утилит SpookyApp.
 *
 * Используется в grid-рендерерах, окнах и панелях.
 *
 * @namespace SpookyApp.util
 */
SpookyApp.util = {

    /**
     * Форматировать дату в относительный формат ("2 часа назад").
     *
     * Логика интервалов:
     * - < 60 сек       → "только что"
     * - < 60 мин       → "N мин. назад"
     * - < 24 часов     → "N ч. назад"
     * - < 7 дней       → "N дн. назад"
     * - < 30 дней      → "N нед. назад"
     * - иначе          → дата в формате DD.MM.YYYY
     *
     * @param {string|Date} dateStr Дата (ISO 8601 или любой parseable формат)
     * @return {string} Относительная дата или отформатированная строка
     *
     * @example
     * SpookyApp.util.formatRelativeDate('2026-03-05T10:00:00Z');
     * // => "3 ч. назад"
     */
    formatRelativeDate: function (dateStr) {
        if (!dateStr) {
            return '—';
        }

        var date;

        // Парсинг: поддержка ISO 8601 и MySQL datetime
        if (dateStr instanceof Date) {
            date = dateStr;
        } else {
            // MySQL: "2026-03-05 10:00:00" → заменяем пробел на T для надёжного парсинга
            var normalized = String(dateStr).replace(' ', 'T');
            date = new Date(normalized);
        }

        if (isNaN(date.getTime())) {
            return String(dateStr);
        }

        var now = new Date();
        var diffMs = now.getTime() - date.getTime();
        var diffSec = Math.floor(diffMs / 1000);

        // Будущее — показываем дату как есть
        if (diffSec < 0) {
            return SpookyApp.util._formatDate(date);
        }

        // < 60 секунд
        if (diffSec < 60) {
            return 'только что';
        }

        // < 60 минут
        var diffMin = Math.floor(diffSec / 60);
        if (diffMin < 60) {
            return diffMin + ' мин. назад';
        }

        // < 24 часов
        var diffHours = Math.floor(diffMin / 60);
        if (diffHours < 24) {
            return diffHours + ' ч. назад';
        }

        // < 7 дней
        var diffDays = Math.floor(diffHours / 24);
        if (diffDays < 7) {
            return diffDays + ' дн. назад';
        }

        // < 30 дней
        var diffWeeks = Math.floor(diffDays / 7);
        if (diffDays < 30) {
            return diffWeeks + ' нед. назад';
        }

        // > 30 дней — полная дата
        return SpookyApp.util._formatDate(date);
    },

    /**
     * Форматировать score в HTML-badge с цветовой индикацией.
     *
     * Цветовая шкала:
     * - >= 80: зелёный (отличная тема)
     * - >= 50: жёлтый (средняя)
     * - >= 20: оранжевый (слабая)
     * - < 20:  красный (нерелевантная)
     *
     * @param {number|string} score Числовое значение score (0–100)
     * @return {string} HTML-строка с цветным badge
     *
     * @example
     * SpookyApp.util.formatScore(85.3);
     * // => '<span class="spookyapp-score spookyapp-score-high"
     * //      style="color:#2e7d32; font-weight:bold;">85.3</span>'
     */
    formatScore: function (score) {
        var value = parseFloat(score);

        if (isNaN(value)) {
            return '<span class="spookyapp-score spookyapp-score-none">—</span>';
        }

        value = Math.round(value * 10) / 10; // Округляем до 1 знака

        var color, cssClass, label;

        if (value >= 80) {
            color = '#2e7d32';       // Зелёный
            cssClass = 'high';
            label = 'отлично';
        } else if (value >= 50) {
            color = '#f9a825';       // Жёлтый
            cssClass = 'medium';
            label = 'средне';
        } else if (value >= 20) {
            color = '#ef6c00';       // Оранжевый
            cssClass = 'low';
            label = 'слабо';
        } else {
            color = '#c62828';       // Красный
            cssClass = 'very-low';
            label = 'нерелевантно';
        }

        return '<span class="spookyapp-score spookyapp-score-' + cssClass + '"'
            + ' style="color:' + color + '; font-weight:bold;"'
            + ' title="Score: ' + value + ' (' + label + ')"'
            + '>' + value + '</span>';
    },

    /**
     * Обрезать текст до указанной длины с добавлением "…".
     *
     * Обрезка по последнему пробелу (не разрывает слова).
     *
     * @param {string} text      Исходный текст
     * @param {number} maxLength Максимальная длина (default: 100)
     * @return {string} Обрезанный текст или оригинал (если короче maxLength)
     *
     * @example
     * SpookyApp.util.truncateText('Samsung Galaxy S26 Ultra — новый флагман', 25);
     * // => "Samsung Galaxy S26…"
     */
    truncateText: function (text, maxLength) {
        if (!text || typeof text !== 'string') {
            return '';
        }

        maxLength = maxLength || 100;

        if (text.length <= maxLength) {
            return text;
        }

        // Обрезаем до maxLength
        var truncated = text.substring(0, maxLength);

        // Ищем последний пробел, чтобы не разрывать слово
        var lastSpace = truncated.lastIndexOf(' ');
        if (lastSpace > maxLength * 0.5) {
            truncated = truncated.substring(0, lastSpace);
        }

        return truncated.replace(/[\s,.\-;:]+$/, '') + '…';
    },

    /**
     * Форматировать источник темы в человекочитаемый badge.
     *
     * @param {string} source Идентификатор источника (reddit, newsapi, mobileapi, etc.)
     * @return {string} HTML-badge с иконкой
     *
     * @example
     * SpookyApp.util.formatSource('reddit');
     * // => '<span class="spookyapp-source spookyapp-source-reddit">Reddit</span>'
     */
    formatSource: function (source) {
        if (!source) {
            return '<span class="spookyapp-source">—</span>';
        }

        var sourceMap = {
            'reddit':    { label: 'Reddit',      color: '#ff4500' },
            'newsapi':   { label: 'NewsAPI',     color: '#1a73e8' },
            'mobileapi': { label: 'MobileAPI',   color: '#00897b' },
            'rapidapi':  { label: 'RapidAPI',    color: '#0055da' },
            'hackernews': { label: 'HackerNews', color: '#ff6600' }
        };

        var key = String(source).toLowerCase();
        var info = sourceMap[key] || { label: source, color: '#757575' };

        return '<span class="spookyapp-source spookyapp-source-' + key + '"'
            + ' style="color:' + info.color + '; font-weight:bold;"'
            + '>' + info.label + '</span>';
    },

    /**
     * Форматировать статус темы.
     *
     * @param {string} status Статус (new, exported, archived)
     * @return {string} HTML-badge
     */
    formatStatus: function (status) {
        var statusMap = {
            'new':      { label: 'Новая',         color: '#1565c0', bg: '#e3f2fd' },
            'exported': { label: 'Экспортирована', color: '#2e7d32', bg: '#e8f5e9' },
            'archived': { label: 'В архиве',       color: '#757575', bg: '#f5f5f5' }
        };

        var key = String(status || 'new').toLowerCase();
        var info = statusMap[key] || statusMap['new'];

        return '<span class="spookyapp-status spookyapp-status-' + key + '"'
            + ' style="color:' + info.color + ';'
            + ' background:' + info.bg + ';'
            + ' padding:2px 8px; border-radius:3px; font-size:11px;"'
            + '>' + info.label + '</span>';
    },

    // ── Приватные хелперы ────────────────────────────────────

    /**
     * Форматировать Date в строку DD.MM.YYYY.
     *
     * @param {Date} date Объект Date
     * @return {string} Отформатированная дата
     * @private
     */
    _formatDate: function (date) {
        var day = ('0' + date.getDate()).slice(-2);
        var month = ('0' + (date.getMonth() + 1)).slice(-2);
        var year = date.getFullYear();
        return day + '.' + month + '.' + year;
    },

    /**
     * Форматировать Date в строку DD.MM.YYYY HH:MM.
     *
     * @param {Date} date Объект Date
     * @return {string} Отформатированная дата и время
     * @private
     */
    _formatDateTime: function (date) {
        var hours = ('0' + date.getHours()).slice(-2);
        var minutes = ('0' + date.getMinutes()).slice(-2);
        return SpookyApp.util._formatDate(date) + ' ' + hours + ':' + minutes;
    }
};

// ╔═════════════════════════════════════════════════════════════╗
// ║  4. Lexicon: система переводов                             ║
// ╚═════════════════════════════════════════════════════════════╝

/**
 * Система лексиконов SpookyApp.
 *
 * Хранит переводы UI-строк. Данные подгружаются из PHP:
 *
 * ```php
 * $this->addHtml('<script>Ext.apply(SpookyApp.lexicon._data, '
 *     . json_encode($modx->lexicon->fetch('spookyapp.')) . ');</script>');
 * ```
 *
 * Fallback: если ключ не найден — возвращает defaultValue или сам ключ.
 *
 * @namespace SpookyApp.lexicon
 */
SpookyApp.lexicon = {

    /**
     * Хранилище переводов (key → value).
     *
     * @type {Object.<string, string>}
     * @private
     */
    _data: {},

    /**
     * Получить перевод по ключу.
     *
     * Поиск ведётся по точному совпадению, затем с префиксом 'spookyapp.'.
     *
     * @param {string} key          Ключ лексикона (например 'topicfinder.title')
     * @param {string} [defaultValue] Значение по умолчанию (если не найден)
     * @return {string} Перевод или defaultValue или сам ключ
     *
     * @example
     * SpookyApp.lexicon.get('topicfinder.title', 'Topic Finder');
     * // Если есть перевод → "Поиск тем"
     * // Если нет         → "Topic Finder"
     */
    get: function (key, defaultValue) {
        // Точное совпадение
        if (this._data[key] !== undefined) {
            return this._data[key];
        }

        // С префиксом spookyapp.
        var prefixedKey = 'spookyapp.' + key;
        if (this._data[prefixedKey] !== undefined) {
            return this._data[prefixedKey];
        }

        // Fallback
        return (defaultValue !== undefined) ? defaultValue : key;
    },

    /**
     * Загрузить массив переводов.
     *
     * @param {Object.<string, string>} data Объект с переводами
     * @return {void}
     *
     * @example
     * SpookyApp.lexicon.load({
     *     'spookyapp.topicfinder.title': 'Поиск тем',
     *     'spookyapp.topicfinder.search': 'Искать'
     * });
     */
    load: function (data) {
        if (data && typeof data === 'object') {
            Ext.apply(this._data, data);
        }
    },

    /**
     * Проверить, существует ли перевод для ключа.
     *
     * @param {string} key Ключ лексикона
     * @return {boolean}
     */
    has: function (key) {
        return this._data[key] !== undefined
            || this._data['spookyapp.' + key] !== undefined;
    }
};

// ╔═════════════════════════════════════════════════════════════╗
// ║  5. Инициализация: лог в консоль                           ║
// ╚═════════════════════════════════════════════════════════════╝

if (typeof console !== 'undefined' && console.log) {
    console.log(
        '%c SpookyApp v' + SpookyApp.config.version + ' %c loaded ',
        'background:#1565c0; color:#fff; padding:2px 6px; border-radius:3px 0 0 3px;',
        'background:#e3f2fd; color:#1565c0; padding:2px 6px; border-radius:0 3px 3px 0;'
    );
}