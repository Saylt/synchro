<?php

namespace Tygh\Addons\Synchro\Importers;

use Tygh\Common\OperationResult;
use Tygh\Database\Connection;
use Tygh\Enum\ImagePairTypes;

/**
 * Synchronizes external images with CS-Cart image pairs.
 */
class ImageImporter
{
    /** @var \Tygh\Database\Connection */
    private $database;

    /**
     * Initializes the image importer.
     *
     * @param \Tygh\Database\Connection $database Database connection
     */
    public function __construct(Connection $database)
    {
        $this->database = $database;
    }

    /**
     * Finds image pairs for the specified objects indexed by filename.
     *
     * @param string     $object_type CS-Cart image object type
     * @param array<int> $object_ids  Object identifiers
     *
     * @return array<int, array<string, array{pair_id: int, type: string, position: int, image_path: string}>>
     */
    public function findByObjectIds($object_type, array $object_ids)
    {
        if (!$object_ids) {
            return [];
        }

        $rows = $this->database->getArray(
            'SELECT ?:images_links.object_id, ?:images_links.pair_id, ?:images_links.type,'
            . ' ?:images_links.position, ?:images.image_path FROM ?:images_links'
            . ' INNER JOIN ?:images ON ?:images.image_id = ?:images_links.detailed_id'
            . ' WHERE ?:images_links.object_id IN (?n) AND ?:images_links.object_type = ?s'
            . ' ORDER BY ?:images_links.object_id, ?:images_links.position, ?:images_links.pair_id',
            $object_ids,
            $object_type
        );
        $images = [];

        foreach ($rows as $row) {
            $object_id = (int) $row['object_id'];
            $image_path = (string) $row['image_path'];
            $filename = mb_strtolower(basename($image_path));
            $images[$object_id][$filename] = [
                'pair_id'    => (int) $row['pair_id'],
                'type'       => (string) $row['type'],
                'position'   => (int) $row['position'],
                'image_path' => $image_path,
            ];
        }

        return $images;
    }

    /**
     * Synchronizes an object's images with the supplied external image list.
     *
     * @param int                                                                                 $object_id            Object identifier
     * @param string                                                                              $object_type          CS-Cart image object type
     * @param array<string>                                                                       $image_urls           Source image URLs
     * @param int                                                                                 $updated_at_timestamp Last successful full update timestamp
     * @param array<string, array{pair_id: int, type: string, position: int, image_path: string}> $current_images       Current images indexed by filename
     *
     * @return \Tygh\Common\OperationResult
     */
    public function import($object_id, $object_type, array $image_urls, $updated_at_timestamp, array $current_images)
    {
        $result = new OperationResult(true);
        $detailed_images = [];
        $pairs_data = [];
        $processed_images = [];
        $images_to_delete = $current_images;
        $position = 0;

        foreach ($image_urls as $image_url) {
            $filename = $this->getFilename($image_url);
            if ($filename === '' || isset($processed_images[$filename])) {
                continue;
            }

            $processed_images[$filename] = true;
            $current_image = isset($current_images[$filename]) ? $current_images[$filename] : null;
            unset($images_to_delete[$filename]);

            $type = $position === 0 ? ImagePairTypes::MAIN : ImagePairTypes::ADDITIONAL;
            $pair_data = [
                'type'     => $type,
                'position' => $position,
            ];
            $position++;

            if ($current_image !== null) {
                $pair_data = ['pair_id' => $current_image['pair_id']] + $pair_data;

                if (!$this->isImageModified($image_url, $updated_at_timestamp)) {
                    if ($current_image['type'] !== $type || $current_image['position'] !== $pair_data['position']) {
                        if (
                            $this->database->query(
                                'UPDATE ?:images_links SET type = ?s, position = ?i WHERE pair_id = ?i',
                                $type,
                                $pair_data['position'],
                                $current_image['pair_id']
                            ) === false
                        ) {
                            $result->setSuccess(false);
                            $result->addError(
                                'metadata.' . $current_image['pair_id'],
                                __('synchro.image_import_error.metadata_update_failed', [
                                    '[pair_id]' => $current_image['pair_id'],
                                    '[url]'     => $image_url,
                                ])
                            );
                        }
                    }
                    continue;
                }
            }

            $image_data = fn_get_url_data($image_url);
            if (!$image_data) {
                $result->setSuccess(false);
                $result->addError(
                    'download.' . $filename,
                    __('synchro.image_import_error.download_failed', ['[url]' => $image_url])
                );
                continue;
            }

            $detailed_images[] = $image_data;
            $pairs_data[] = $pair_data;
        }

        if ($pairs_data) {
            $pair_ids = fn_update_image_pairs([], $detailed_images, $pairs_data, $object_id, $object_type);
            if (count($pair_ids) !== count($pairs_data)) {
                $result->setSuccess(false);
                $result->addError(
                    'update_pairs',
                    __('synchro.image_import_error.update_failed', [
                        '[object_id]'   => $object_id,
                        '[object_type]' => $object_type,
                    ])
                );
            }
        }

        if ($result->isFailure()) {
            return $result;
        }

        foreach ($images_to_delete as $current_image) {
            if (!fn_delete_image_pair($current_image['pair_id'], $object_type)) {
                $result->setSuccess(false);
                $result->addError(
                    'delete.' . $current_image['pair_id'],
                    __('synchro.image_import_error.delete_failed', [
                        '[pair_id]'     => $current_image['pair_id'],
                        '[object_id]'   => $object_id,
                        '[object_type]' => $object_type,
                    ])
                );
            }
        }

        return $result;
    }

    /**
     * Extracts a normalized filename from an external image URL.
     *
     * @param string $image_url Image URL
     *
     * @return string
     */
    private function getFilename($image_url)
    {
        $image_path = parse_url($image_url, PHP_URL_PATH);

        return mb_strtolower(basename(urldecode($image_path === false ? $image_url : $image_path)));
    }

    /**
     * Checks whether an external image is newer than the last successful entity update.
     *
     * @param string $image_url            Image URL
     * @param int    $updated_at_timestamp Last successful full update timestamp
     *
     * @return bool
     */
    private function isImageModified($image_url, $updated_at_timestamp)
    {
        if (!$updated_at_timestamp) {
            return true;
        }

        $headers = @get_headers($image_url, 1);
        if (!is_array($headers)) {
            return true;
        }

        $headers = array_change_key_case($headers, CASE_LOWER);
        if (!isset($headers['last-modified'])) {
            return true;
        }

        $last_modified = $headers['last-modified'];
        if (is_array($last_modified)) {
            $last_modified = end($last_modified);
        }

        $modified_timestamp = is_string($last_modified) ? strtotime($last_modified) : false;

        return $modified_timestamp === false || $modified_timestamp > $updated_at_timestamp;
    }
}
