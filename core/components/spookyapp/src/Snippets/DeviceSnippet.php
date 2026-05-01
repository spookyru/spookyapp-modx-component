<?php
// filepath: core/components/spookyapp/src/Snippets/DeviceSnippet.php

/**
 * SpookyApp — DeviceSnippet
 *
 * ═══════════════════════════════════════════════════════════════
 * Сниппет для вывода информации об устройстве.
 * Данные загружаются из БД (спецификации хранятся в JSON).
 *
 * @package SpookyApp
 * @since   1.0.0
 * ═══════════════════════════════════════════════════════════════
 */

namespace SpookyApp\Snippets;

use SpookyApp\Services\API\MobileDevicesAPIService;

class DeviceSnippet extends BaseChunkSnippet
{
    protected function getContentType(): string
    {
        return 'device';
    }

    protected function getDefaultTemplate(): string
    {
        return 'device';
    }

    protected function getServiceClass(): string
    {
        return MobileDevicesAPIService::class;
    }

    protected function getServiceMethod(): string
    {
        return 'getDevice';
    }

    /**
     * Подготовка placeholders из сырых данных.
     */
    protected function prepareData(array $raw): array
    {
        $brand = $raw['brand'] ?? '';
        $model = $raw['model'] ?? ($raw['name'] ?? '');
        $title = $raw['title'] ?? trim($brand . ' ' . $model);

        $data = [
            'title'         => $title,
            'brand'         => $brand,
            'model'         => $model,
            'poster_path'   => $raw['poster_path'] ?? ($raw['image'] ?? ''),
            'release_date'  => $raw['release_date'] ?? ($raw['announced'] ?? ''),
            'price'         => $raw['price'] ?? '',
        ];

        // ── Спецификации ────────────────────────────────────
        // Данные могут быть как в плоской структуре, так и во вложенном specs
        $specs = $raw['specs'] ?? $raw;

        $specFields = [
            'os'             => ['os', 'operating_system'],
            'display_size'   => ['display_size', 'screen_size'],
            'display_type'   => ['display_type', 'screen_type'],
            'display_res'    => ['display_res', 'display_resolution', 'screen_resolution'],
            'chipset'        => ['chipset', 'processor', 'soc'],
            'ram'            => ['ram', 'memory'],
            'storage'        => ['storage', 'internal_storage'],
            'camera_main'    => ['camera_main', 'main_camera', 'rear_camera'],
            'camera_selfie'  => ['camera_selfie', 'front_camera', 'selfie_camera'],
            'battery'        => ['battery', 'battery_capacity'],
            'charging'       => ['charging', 'fast_charging'],
            'dimensions'     => ['dimensions', 'body_dimensions'],
            'weight'         => ['weight', 'body_weight'],
            'network'        => ['network', 'connectivity'],
            'nfc'            => ['nfc'],
            'waterproof'     => ['waterproof', 'water_resistance', 'ip_rating'],
            'colors'         => ['colors', 'available_colors'],
        ];

        foreach ($specFields as $placeholder => $candidates) {
            $data[$placeholder] = '';
            foreach ($candidates as $key) {
                if (!empty($specs[$key])) {
                    $value = $specs[$key];
                    $data[$placeholder] = is_array($value) ? implode(', ', $value) : (string) $value;
                    break;
                }
            }
        }

        // ── Дополнительные спецификации ─────────────────────
        $data['specs_extra'] = $raw['specs_extra'] ?? '';

        // ── Ссылка-источник ─────────────────────────────────
        $data['source_url'] = $raw['source_url'] ?? ($raw['url'] ?? '');

        return $data;
    }
}