<?php

namespace Drupal\nexx_integration\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Utility\Token;
use Drupal\media_entity\MediaInterface;
use Drupal\media_entity\Entity\Media;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Class Omnia.
 *
 * @package Drupal\nexx_integration\Controller
 */
class OmniaController extends ControllerBase {
  /**
   * The database service.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfo
   */
  protected $entityTypeBundleInfo;

  /**
   * The media entity.
   *
   * @var \Drupal\media_entity\MediaInterface
   */
  protected $mediaEntity;

  /**
   * The media entity storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $mediaEntityStorage;

  /**
   * The media entity definition.
   *
   * @var \Drupal\Core\Entity\EntityTypeInterface
   */
  protected $mediaEntityDefinition;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * Omnia constructor.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database service.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   * @param \Drupal\Core\Utility\Token $token
   *   Token service.
   */
  public function __construct(
    Connection $database,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    EntityFieldManagerInterface $entity_field_manager,
    LoggerInterface $logger,
    Token $token
  ) {
    $this->database = $database;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->entityFieldManager = $entity_field_manager;
    $this->logger = $logger;
    $this->token = $token;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity_field.manager'),
      $container->get('logger.factory')->get('nexx_integration'),
      $container->get('token')
    );
  }

  /**
   * Search and edit videos.
   */
  public function videoList() {
    return [
      '#theme' => 'omnia_editor',
      '#auth_key' => $this->config('nexx_integration.settings')
        ->get('nexx_api_authkey'),
    ];
  }

  /**
   * Endpoint for video creation / update.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response.
   *
   * @throws \Exception
   */
  public function video(Request $request) {

    $response = new JsonResponse();
    $content = $request->getContent();
    $query = $this->mediaEntityStorage()->getQuery();

    $token = $request->query->get('token', NULL);
    if (NULL !== $token) {

      $config = $this->config('nexx_integration.settings');
      if ($token != $config->get('notification_access_key')) {
        throw new AccessDeniedHttpException();
      }
    }
    else {
      throw new AccessDeniedHttpException();
    }

    if (!empty($content)) {
      $videoData = json_decode($content);
    }
    if (!isset($videoData->itemID)) {
      throw new \Exception('ItemID missing');
    }

    $this->logger->info('Incoming video "@title" (nexx id: @id)',
      [
        '@title' => $videoData->itemData->title,
        '@id' => $videoData->itemID,
      ]
    );

    $video_field = $this->videoFieldName();
    $ids = $query->condition($video_field . '.item_id', $videoData->itemID)
      ->execute();

    // Delete video if incomming isDeleted is 1.
    if ($videoData->itemStates->isDeleted == 1) {
      if ($id = array_pop($ids)) {
        $media = $this->mediaEntity($id);
        $media->delete();
        $this->logger->info('Deleted video "@title" (Drupal id: @id)',
          [
            '@title' => $videoData->itemData->title,
            '@id' => $id,
          ]
        );
        $response->setData([
          'refnr' => $videoData->itemID,
          'value' => $id,
        ]);
        return $response;
      }
      $response->setData([
        'refnr' => $videoData->itemID,
        'value' => NULL,
      ]);
      return $response;
    }

    // Edit video.
    elseif ($id = array_pop($ids)) {
      $media = $this->mediaEntity($id);
      $this->logger->info('Updated video "@title" (Drupal id: @id)',
        [
          '@title' => $videoData->itemData->title,
          '@id' => $media->id(),
        ]
      );
    }

    // Create video.
    else {
      $media = $this->mediaEntity();
      $this->logger->info('Created video "@title" (Drupal id: @id)',
        [
          '@title' => $videoData->itemData->title,
          '@id' => $media->id(),
        ]
      );
    }

    $this->mapData($media, $videoData);
    $this->setState($media, $videoData);

    $media->save();
    $response->setData([
      'refnr' => $videoData->itemID,
      'value' => $media->id(),
    ]);
    return $response;
  }

  /**
   * Get the media entity.
   *
   * @param int|null $id
   *   Media entity id or NULL to create new one.
   *
   * @return \Drupal\Core\Entity\EntityInterface|\Drupal\media_entity\MediaInterface|null
   *   Media entity or NULL if no matching entity is found.
   */
  protected function mediaEntity($id = NULL) {
    if (!isset($this->mediaEntity)) {
      $storage = $this->mediaEntityStorage();
      if ($id) {
        $this->mediaEntity = $storage->load($id);
      }
      else {
        $videoBundle = $this->config('nexx_integration.settings')
          ->get('video_bundle');
        $this->mediaEntity = $storage->create(['bundle' => $videoBundle]);
      }
    }
    return $this->mediaEntity;
  }

  /**
   * Set proper publish state to media entity.
   *
   * @param \Drupal\media_entity\MediaInterface $media
   *   Media entity.
   * @param object $videoData
   *   Incoming VideoData object from Nexx.tv.
   */
  protected function setState(MediaInterface $media, $videoData) {

    $status = Media::NOT_PUBLISHED;

    if ($videoData->itemStates->active == 1
      && $videoData->itemStates->isSSC == 1
    ) {
      $status = Media::PUBLISHED;

      $this->logger->info('Published video "@title" (Drupal id: @id)',
        [
          '@title' => $videoData->itemData->title,
          '@id' => $media->id(),
        ]
      );
    }
    else {
      $this->logger->info('Unpublished video "@title" (Drupal id: @id)... States: 
      active:@active 
      isSSC:@isSSC 
      validfrom_ssc:@validfrom_ssc 
      validto_ssc:@validto_ssc ',
        [
          '@title' => $videoData->itemData->title,
          '@id' => $media->id(),
          '@active' => $videoData->itemStates->active,
          '@isSSC' => $videoData->itemStates->isSSC,
          '@validfrom_ssc' => $videoData->itemStates->validfrom_ssc,
          '@validto_ssc' => $videoData->itemStates->validto_ssc,
        ]
      );
    }

    $media->set("status", $status);
  }

  /**
   * Map incoming nexx video data to media entity fields.
   *
   * @param \Drupal\media_entity\MediaInterface $media
   *   Media entity.
   * @param object $videoData
   *   Incomming videoData object from Nexx.tv.
   */
  protected function mapData(MediaInterface $media, $videoData) {
    $entityType = $this->mediaEntityDefinition();
    $videoField = $this->videoFieldName();

    $labelKey = $entityType->getKey('label');

    $title = !empty($videoData->itemData->title) ? $videoData->itemData->title : '';
    $actor_ids = !empty($videoData->itemData->actors_ids) ? explode(',', $videoData->itemData->actors_ids) : [];
    $tag_ids = !empty($videoData->itemData->tags_ids) ? explode(',', $videoData->itemData->tags_ids) : [];
    $channel_id = !empty($videoData->itemData->channel_id) ? $videoData->itemData->channel_id : 0;

    $media->$videoField->item_id = !empty($videoData->itemID) ? $videoData->itemID : 0;
    $media->$videoField->title = $title;
    $media->$videoField->hash = !empty($videoData->itemData->hash) ? $videoData->itemData->hash : '';
    $media->$videoField->alttitle = !empty($videoData->itemData->alttitle) ? $videoData->itemData->alttitle : '';
    $media->$videoField->subtitle = !empty($videoData->itemData->subtitle) ? $videoData->itemData->subtitle : '';
    $media->$videoField->teaser = !empty($videoData->itemData->teaser) ? $videoData->itemData->teaser : '';
    $media->$videoField->description = !empty($videoData->itemData->description) ? substr($videoData->itemData->description, 0, 256) : '';
    $media->$videoField->altdescription = !empty($videoData->itemData->altdescription) ? substr($videoData->itemData->altdescription, 0, 256) : '';
    $media->$videoField->uploaded = !empty($videoData->itemData->uploaded) ? $videoData->itemData->uploaded : NULL;
    $media->$videoField->channel_id = $channel_id;
    // This is redundant, actors will be mapped later. I do not remove it yet,
    // because we still have to make sure, that there is no legacy usage.
    $media->$videoField->actors_ids = implode(",", $actor_ids);
    $media->$videoField->isSSC = !empty($videoData->itemStates->isSSC) ? $videoData->itemStates->isSSC : 0;
    $media->$videoField->encodedSSC = !empty($videoData->itemStates->encodedSSC) ? $videoData->itemStates->encodedSSC : 0;
    $media->$videoField->validfrom_ssc = !empty($videoData->itemStates->validfrom_ssc) ? $videoData->itemStates->validfrom_ssc : 0;
    $media->$videoField->validto_ssc = !empty($videoData->itemStates->validto_ssc) ? $videoData->itemStates->validto_ssc : 0;
    $media->$videoField->encodedHTML5 = !empty($videoData->itemStates->encodedHTML5) ? $videoData->itemStates->encodedHTML5 : 0;
    $media->$videoField->isMOBILE = !empty($videoData->itemStates->isMOBILE) ? $videoData->itemStates->isMOBILE : 0;
    $media->$videoField->encodedMOBILE = !empty($videoData->itemStates->encodedMOBILE) ? $videoData->itemStates->encodedMOBILE : 0;
    $media->$videoField->validfrom_mobile = !empty($videoData->itemStates->validfrom_mobile) ? $videoData->itemStates->validfrom_mobile : 0;
    $media->$videoField->validto_mobile = !empty($videoData->itemStates->validto_mobile) ? $videoData->itemStates->validto_mobile : 0;
    $media->$videoField->isHYVE = !empty($videoData->itemStates->isHYVE) ? $videoData->itemStates->isHYVE : 0;
    $media->$videoField->encodedHYVE = !empty($videoData->itemStates->encodedHYVE) ? $videoData->itemStates->encodedHYVE : 0;
    $media->$videoField->validfrom_hyve = !empty($videoData->itemStates->validfrom_hyve) ? $videoData->itemStates->validfrom_hyve : 0;
    $media->$videoField->validto_hyve = !empty($videoData->itemStates->validto_hyve) ? $videoData->itemStates->validto_hyve : 0;
    $media->$videoField->active = !empty($videoData->itemStates->active) ? $videoData->itemStates->active : 0;
    $media->$videoField->isDeleted = !empty($videoData->itemStates->isDeleted) ? $videoData->itemStates->isDeleted : 0;
    $media->$videoField->isBlocked = !empty($videoData->itemStates->isBlocked) ? $videoData->itemStates->isBlocked : 0;
    $media->$videoField->encodedTHUMBS = !empty($videoData->itemStates->encodedTHUMBS) ? $videoData->itemStates->encodedTHUMBS : 0;
    $media->$videoField->copyright = !empty($videoData->itemData->copyright) ? $videoData->itemData->copyright : '';
    $media->$videoField->runtime = !empty($videoData->itemData->runtime) ? $videoData->itemData->runtime : '00:00:00';

    // Copy title to label field.
    $media->$labelKey = $title;

    $media_config = $media->getType()->getConfiguration();

    if (!empty($media_config['channel_field'])) {
      $channelField = $media_config['channel_field'];
    }

    if (!empty($media_config['actor_field'])) {
      $actorField = $media_config['actor_field'];
    }

    if (!empty($media_config['tag_field'])) {
      $tagField = $media_config['tag_field'];
    }

    if (!empty($media_config['teaser_image_field'])) {
      $teaserImageField = $media_config['teaser_image_field'];
    }

    if (!empty($media_config['description_field'])) {
      $descriptionField = $media_config['description_field'];
    }

    // Update taxonomy references.
    if (!empty($channelField) && !empty($channel_id)) {
      $term_id = $this->mapTermId($channel_id);
      if (!empty($term_id)) {
        $media->$channelField = $term_id;
      }
      else {
        $this->logger->warning('Unknown ID @term_id for term "@term_name"',
          [
            '@term_id' => $channel_id,
            '@term_name' => $videoData->itemData->channel,
          ]
        );
      }
    }

    if (!empty($actorField)) {
      $mapped_actor_ids = $this->mapMultipleTermIds($actor_ids);
      $media->$actorField = $mapped_actor_ids;
    }

    if (!empty($tagField)) {
      $mapped_tag_ids = $this->mapMultipleTermIds($tag_ids);
      $media->$tagField = $mapped_tag_ids;
    }

    if (!empty($teaserImageField) && $media->$videoField->thumb !== $videoData->itemData->thumb) {
      if (!empty($videoData->itemData->thumb)) {
        $media->$videoField->thumb = $videoData->itemData->thumb;
        $this->mapTeaserImage($media, $teaserImageField, $videoData);
      }
      else {
        $media->$videoField->thumb = '';
      }
    }

    // Map the description.
    if (!empty($descriptionField)) {
      $media->$descriptionField = $videoData->itemData->description;
    }

    // Media entity does not update mapped fields by itself.
    foreach ($media->bundle->entity->field_map as $source_field => $destination_field) {
      if ($media->hasField($destination_field) && ($value = $media->getType()->getField($media, $source_field))) {
        $media->set($destination_field, $value);
      }
    }
  }

  /**
   * Map multiple omnia term ids to drupal term ids.
   *
   * @param int[] $omnia_ids
   *   Array of omnia termn ids.
   *
   * @return int[]
   *   Array of mapped drupal ids, might contain less ids then the input array.
   */
  protected function mapMultipleTermIds(array $omnia_ids) {
    $drupal_ids = [];
    foreach ($omnia_ids as $omnia_id) {
      $drupalId = $this->mapTermId($omnia_id);
      if ($drupalId) {
        $drupal_ids[] = $drupalId;
      }
      else {
        $this->logger->warning('Unknown omnia ID @term_id"',
          [
            '@term_id' => $omnia_id,
          ]
        );
      }
    }

    return $drupal_ids;
  }

  /**
   * Map omnia term Id to corresponding drupal term id.
   *
   * @param int $omnia_id
   *   The omnia id of the term.
   *
   * @return int
   *   The drupal id of the term.
   */
  protected function mapTermId($omnia_id) {
    $result = $this->database->select('nexx_taxonomy_term_data', 'n')
      ->fields('n', ['tid'])
      ->condition('n.nexx_item_id', $omnia_id)
      ->execute();

    $drupal_id = $result->fetchField();

    return $drupal_id;
  }

  /**
   * Map incoming teaser image to medie entity field.
   *
   * @param \Drupal\media_entity\MediaInterface $media
   *   The media entity.
   * @param string $teaserImageField
   *   The machine name of the field, that stores the file.
   * @param mixed $videoData
   *   The video data object from the request.
   *
   * @throws \Exception
   */
  protected function mapTeaserImage(MediaInterface $media, $teaserImageField, $videoData) {
    $images_field = $media->$teaserImageField;
    if (empty($images_field)) {
      return;
    }
    $images_field_target_type = $images_field->getSetting('target_type');

    /*
     * TODO: there must be a better way to get this information,
     *       then creating a dummy object
     */
    $images_field_target_bundle = array_shift($images_field->getSetting('handler_settings')['target_bundles']);
    if (empty($images_field_target_bundle)) {
      throw new \Exception('No image field target bundle.');
    }
    $storage = $this->entityTypeManager()
      ->getStorage($images_field_target_type);

    $thumbnail_entity = $storage->create([
      'bundle' => $images_field_target_bundle,
      'name' => $media->label(),
    ]);
    $updated_thumbnail_entity = FALSE;

    if ($thumb_uri = $videoData->itemData->thumb) {
      // Get configured source field from media entity type definition.
      $thumbnail_upload_field = $thumbnail_entity->getType()
        ->getConfiguration()['source_field'];
      // Get field settings from this field.
      $thumbnail_upload_field_settings = $thumbnail_entity->getFieldDefinition($thumbnail_upload_field)
        ->getSettings();
      // Use file directory and uri_scheme out of these settings to create
      // destination directory for file upload.
      $upload_directory = $this->token->replace($thumbnail_upload_field_settings['file_directory']);
      $destination_file = $thumbnail_upload_field_settings['uri_scheme'] . '://' . $upload_directory . '/' . basename($thumb_uri);
      $destination_directory = dirname($destination_file);
      if ($destination_directory) {
        // Import file.
        file_prepare_directory($destination_directory, FILE_CREATE_DIRECTORY | FILE_MODIFY_PERMISSIONS);
        $thumbnail = file_save_data(file_get_contents($thumb_uri), $destination_file, FILE_EXISTS_REPLACE);
        // Add this file to thumbnail field of the nexx media entity.
        $thumbnail_entity->$thumbnail_upload_field->appendItem([
          'target_id' => $thumbnail->id(),
          'alt' => $media->label(),
        ]);
        $updated_thumbnail_entity = TRUE;
      }
    }
    // If new thumbnails were found,
    // safe the thumbnail media entity and link it to the nexx media entity.
    if ($updated_thumbnail_entity) {
      $thumbnail_entity->save();
      $media->$teaserImageField = ['target_id' => $thumbnail_entity->id()];
    }
  }

  /**
   * Retrieves the media entity storage.
   *
   * @return \Drupal\Core\Entity\EntityStorageInterface
   *   The entity type manager.
   */
  protected function mediaEntityStorage() {
    if (!isset($this->mediaEntityStorage)) {
      $this->mediaEntityStorage = $this->entityTypeManager()
        ->getStorage('media');
    }
    return $this->mediaEntityStorage;
  }

  /**
   * Retrieves the media entity definition.
   *
   * @return \Drupal\Core\Entity\EntityTypeInterface
   *   The entity type manager.
   */
  protected function mediaEntityDefinition() {
    if (!isset($this->mediaEntityDefinition)) {
      $this->mediaEntityDefinition = $this->entityTypeManager()
        ->getDefinition('media');
    }
    return $this->mediaEntityDefinition;
  }

  /**
   * Retrieve video data field name.
   *
   * @return string
   *   The name of the field.
   *
   * @throws \Exception
   */
  protected function videoFieldName() {
    $entity_type_id = 'media';
    $videoBundle = $this->config('nexx_integration.settings')
      ->get('video_bundle');

    $fieldDefinitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $videoBundle);
    foreach ($fieldDefinitions as $fieldname => $fieldDefinition) {
      if ($fieldDefinition->getType() === 'nexx_video_data') {
        $videoField = $fieldname;
        break;
      }
    }

    if (empty($videoField)) {
      throw new \Exception('No video data field defined');
    }

    return $videoField;
  }

}
