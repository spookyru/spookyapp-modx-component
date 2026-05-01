<?php

declare(strict_types=1);

use MODX\Revolution\Processors\Processor;
use MODX\Revolution\modX;
use SpookyApp\Model\SpookyAppChunk;

/**
 * SpookyAppChunkGeneratorSaveChunkProcessor вЂ” СЃРѕС…СЂР°РЅРµРЅРёРµ С‡Р°РЅРєР° РІ Р‘Р”.
 *
 * в•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђ
 * РЎРѕС…СЂР°РЅСЏРµС‚ СЃРіРµРЅРµСЂРёСЂРѕРІР°РЅРЅС‹Р№ С‡Р°РЅРє РІ С‚Р°Р±Р»РёС†Сѓ spookyapp_chunks.
 * Р•СЃР»Рё Р·Р°РїРёСЃСЊ СЃ С‚Р°РєРёРј type + external_id СѓР¶Рµ СЃСѓС‰РµСЃС‚РІСѓРµС‚ вЂ” UPDATE,
 * РёРЅР°С‡Рµ вЂ” INSERT.
 *
 * РџР°СЂР°РјРµС‚СЂС‹:
 *   - type (string):        РўРёРї РєРѕРЅС‚РµРЅС‚Р° (movie|tv|game|device|product|...)
 *   - external_id (string): ID СЌР»РµРјРµРЅС‚Р° РІРѕ РІРЅРµС€РЅРµРј API
 *   - title (string):       РќР°Р·РІР°РЅРёРµ СЌР»РµРјРµРЅС‚Р°
 *   - data (string/JSON):   РџРѕР»РЅС‹Рµ РґР°РЅРЅС‹Рµ СЌР»РµРјРµРЅС‚Р°
 *   - chunk_code (string):  РЎРіРµРЅРµСЂРёСЂРѕРІР°РЅРЅС‹Р№ HTML-РєРѕРґ С‡Р°РЅРєР°
 *
 * Р’РѕР·РІСЂР°С‰Р°РµС‚:
 *   - success: true/false
 *   - chunk_id: ID СЃРѕС…СЂР°РЅС‘РЅРЅРѕР№ Р·Р°РїРёСЃРё
 *   - action: 'created' РёР»Рё 'updated'
 * в•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђ
 *
 * @package SpookyApp
 * @subpackage Processors\ChunkGenerator
 */
class SpookyAppChunkGeneratorSaveChunkProcessor extends Processor
{
    public $languageTopics = ['spookyapp:chunkgenerator'];

    // в•”в•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•—
    // в•‘  Initialize                                             в•‘
    // в•љв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ќ

    public function initialize(): bool|string
    {
        // PSR-4 autoloader
        $autoload = MODX_CORE_PATH . 'components/spookyapp/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }

        // Р РµРіРёСЃС‚СЂРёСЂСѓРµРј xPDO-РїР°РєРµС‚ (РёРґРµРјРїРѕС‚РµРЅС‚РЅРѕ; prefix Р±РµСЂС‘С‚СЃСЏ РёР· РєРѕРЅС„РёРіР° MODX)
        $this->modx->addPackage(
            'SpookyApp\\Model',
            MODX_CORE_PATH . 'components/spookyapp/src/Model/'
        );

        // Р’Р°Р»РёРґР°С†РёСЏ РѕР±СЏР·Р°С‚РµР»СЊРЅС‹С… РїР°СЂР°РјРµС‚СЂРѕРІ
        foreach (['type', 'external_id', 'title'] as $param) {
            if (trim((string)$this->getProperty($param, '')) === '') {
                return 'Parameter "' . $param . '" is required';
            }
        }

        return parent::initialize();
    }

    // в•”в•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•—
    // в•‘  Process                                                в•‘
    // в•љв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ђв•ќ

    public function process(): array
    {
        $type       = trim((string)$this->getProperty('type'));
        $externalId = trim((string)$this->getProperty('external_id'));
        $title      = trim((string)$this->getProperty('title'));
        $dataRaw    = $this->getProperty('data', '{}');

        // РќРѕСЂРјР°Р»РёР·СѓРµРј data в†’ JSON-СЃС‚СЂРѕРєР°
        if (is_array($dataRaw)) {
            $dataJson = json_encode($dataRaw, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } else {
            $decoded  = json_decode((string)$dataRaw, true);
            $dataJson = ($decoded !== null)
                ? json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                : '{}';
        }

        $decodedPreview = json_decode((string)($this->getProperty('data', '{}')), true) ?: [];
        $this->modx->log(
            modX::LOG_LEVEL_ERROR,
            '[ChunkGenerator:SaveChunk] type=' . $type
            . ' external_id=' . $externalId
            . ' title="' . mb_substr($title, 0, 50) . '"'
            . ' | keys=' . implode(',', array_keys($decodedPreview))
            . ' | movie_credits=' . count($decodedPreview['movie_credits'] ?? [])
            . ' | tv_credits=' . count($decodedPreview['tv_credits'] ?? [])
        );

        try {
            /** @var SpookyAppChunk|null $chunk */
            $chunk = $this->modx->getObject(SpookyAppChunk::class, [
                'type'        => $type,
                'external_id' => $externalId,
            ]);

            if ($chunk !== null) {
                $action = 'updated';
            } else {
                /** @var SpookyAppChunk $chunk */
                $chunk = $this->modx->newObject(SpookyAppChunk::class);
                $chunk->set('type', $type);
                $chunk->set('external_id', $externalId);
                $action = 'created';
            }

            $chunk->set('title', $title);
            $chunk->set('data', $dataJson);
            // SpookyAppChunk::save() Р°РІС‚Рѕ-Р·Р°РїРѕР»РЅСЏРµС‚ created_at / updated_at

            if (!$chunk->save()) {
                throw new \RuntimeException(
                    'xPDO could not save SpookyAppChunk'
                    . ' (type=' . $type . ', external_id=' . $externalId . ')'
                );
            }

            $chunkId = (int)$chunk->get('id');

            // ── Кеширование изображений при сохранении ────────────────────────
            // Скачиваем все внешние URL (TMDB, RAWG, …) в локальную папку
            // assets/spookyapp/{type}/{chunk_id}/ используя cURL —
            // то же сетевое окружение, что и у TMDB API-клиента.
            // Если всё скачалось успешно → обновляем data в БД с локальными путями
            // и создаём маркер .cached, чтобы display-сниппет не пытался скачивать.
            $decoded = json_decode($dataJson, true) ?: [];
            $imgDir  = MODX_BASE_PATH . 'assets/spookyapp/' . $type . '/' . $chunkId . '/';
            $imgWeb  = '/assets/spookyapp/' . $type . '/' . $chunkId . '/';

            if (!is_dir($imgDir)) {
                @mkdir($imgDir, 0755, true);
            }
            // Сбрасываем старый маркер — при обновлении могут быть новые изображения
            @unlink($imgDir . '.cached');

            // ── DNS-over-HTTPS bypass для image.tmdb.org ─────────────────────
            // image.tmdb.org DNS-заблокирован провайдером (возвращает 0.0.0.0),
            // но IP-адрес реально доступен. Cloudflare DoH работает через HTTPS:443.
            // Разрезолвив реальный IP, передаём его через CURLOPT_RESOLVE.
            $curlResolve = [];
            $dohCh = curl_init('https://cloudflare-dns.com/dns-query?name=image.tmdb.org&type=A');
            curl_setopt_array($dohCh, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Accept: application/dns-json'],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT        => 5,
            ]);
            $dohResult = curl_exec($dohCh);
            curl_close($dohCh);
            if ($dohResult) {
                $dohData = json_decode($dohResult, true);
                foreach ($dohData['Answer'] ?? [] as $ans) {
                    if (($ans['type'] ?? 0) === 1) {
                        $curlResolve = [
                            'image.tmdb.org:443:' . $ans['data'],
                            'image.tmdb.org:80:'  . $ans['data'],
                        ];
                        break;
                    }
                }
            }
            // ── / DoH bypass ──────────────────────────────────────────────────

            $imgFailed   = false;
            $cacheImgNow = static function (string $url) use ($imgDir, $imgWeb, &$imgFailed, $curlResolve): string {
                if (empty($url) || !preg_match('#^https?://#i', $url)) {
                    return $url;
                }
                $urlPath  = parse_url($url, PHP_URL_PATH) ?: '';
                $ext      = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'], true)) {
                    $ext = 'jpg';
                }
                $filename  = md5($url) . '.' . $ext;
                $localPath = $imgDir . $filename;

                if (!file_exists($localPath)) {
                    $ch   = curl_init($url);
                    $opts = [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_TIMEOUT        => 15,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_USERAGENT      => 'SpookyApp/1.0 image-cache',
                    ];
                    if (!empty($curlResolve)) {
                        $opts[CURLOPT_RESOLVE] = $curlResolve;
                    }
                    curl_setopt_array($ch, $opts);
                    $imgData  = curl_exec($ch);
                    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($imgData === false || $httpCode < 200 || $httpCode >= 300) {
                        $imgFailed = true;
                        return $url;
                    }
                    file_put_contents($localPath, $imgData);
                }

                return $imgWeb . $filename;
            };

            // Скалярные поля с изображениями
            foreach (['poster', 'backdrop', 'photo', 'image', 'image_url', 'avatar_url', 'poster_away'] as $f) {
                if (!empty($decoded[$f]) && is_string($decoded[$f])) {
                    $decoded[$f] = $cacheImgNow($decoded[$f]);
                }
            }
            // Массивы строк-URL
            foreach (['screenshots', 'images'] as $f) {
                if (!empty($decoded[$f]) && is_array($decoded[$f])) {
                    $decoded[$f] = array_map(
                        static fn($u) => is_string($u) ? $cacheImgNow($u) : $u,
                        $decoded[$f]
                    );
                }
            }
            // Массивы объектов с вложенным полем-изображением
            foreach (['cast' => 'photo', 'crew' => 'photo', 'networks' => 'logo_path',
                      'production_companies' => 'logo_path', 'seasons' => 'poster'] as $field => $imgKey) {
                if (!empty($decoded[$field]) && is_array($decoded[$field])) {
                    foreach ($decoded[$field] as &$item) {
                        if (isset($item[$imgKey]) && is_string($item[$imgKey]) && $item[$imgKey] !== '') {
                            $item[$imgKey] = $cacheImgNow($item[$imgKey]);
                        }
                    }
                    unset($item);
                }
            }

            // Все изображения скачаны → сохраняем локальные пути в БД + маркер
            if (!$imgFailed) {
                $cachedDataJson = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                $chunk->set('data', $cachedDataJson);
                $chunk->save();
                @file_put_contents($imgDir . '.cached', '1');
                $this->modx->log(modX::LOG_LEVEL_INFO,
                    '[ChunkGenerator:SaveChunk] Images cached → id=' . $chunkId);
            } else {
                $this->modx->log(modX::LOG_LEVEL_INFO,
                    '[ChunkGenerator:SaveChunk] Some images failed to cache → id=' . $chunkId);
            }

            // Сбрасываем MODX output-кеш для всех форматов этого чанка
            foreach (['card', 'html', 'brief', 'telegram', 'text'] as $fmt) {
                $this->modx->cacheManager->delete(
                    'spookyapp/chunk_output/' . $chunkId . '/' . $fmt,
                    ['cache_handler' => 'xPDOFileCache']
                );
            }
            // ── / Кеширование изображений ──────────────────────────────────────

            $this->modx->log(
                modX::LOG_LEVEL_INFO,
                '[ChunkGenerator:SaveChunk] ' . $action . ' chunk id=' . $chunkId
            );

            return $this->success('', [
                'chunk_id'     => $chunkId,
                'action'       => $action,
                'type'         => $type,
                'external_id'  => $externalId,
                'title'        => $title,
                'images_ready' => !$imgFailed,
            ]);

        } catch (\Throwable $e) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                '[ChunkGenerator:SaveChunk] Error: ' . $e->getMessage()
            );
            return $this->failure('Failed to save chunk: ' . $e->getMessage());
        }
    }
}

return 'SpookyAppChunkGeneratorSaveChunkProcessor';
