<?php

namespace Drupal\nexx_integration\Plugin\Field\FieldFormatter;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Plugin implementation of the 'nexx_video_player' formatter.
 *
 * @FieldFormatter(
 *   id = "nexx_video_player",
 *   module = "nexx_integration",
 *   label = @Translation("Javascript Video Player"),
 *   field_types = {
 *     "nexx_video_data"
 *   }
 * )
 */
class NexxVideoPlayer extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode = NULL) {
    $elements = [];

    foreach ($items as $delta => $item) {
      $elements[$delta] = [
        '#theme' => 'nexx_player',
        '#container_id' => 'player--' . Crypt::randomBytesBase64(8),
        '#video_id' => $item->item_id,
        // TODO #autoplay should be configurable.
        '#autoplay' => '1',
        '#attached' => [
          'library' => [
            'nexx_integration/base',
          ],
        ],
        /*
        '#cache' => [
          'tags' => $user->getCacheTags(),
        ],
         */
      ];
    }

    return $elements;
  }

}
