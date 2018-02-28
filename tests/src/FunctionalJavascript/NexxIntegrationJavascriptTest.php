<?php

namespace Drupal\Tests\nexx_integration\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\JavascriptTestBase;

/**
 * Tests the JavaScript functionality of the block add filter.
 *
 * @group nexx_integration
 */
class NexxIntegrationJavascriptTest extends JavascriptTestBase {

  use NexxTestTrait;

  public static $modules = [
    'taxonomy',
    'nexx_integration',
    'field_ui',
    'field',
  ];

  /**
   * Test that editing a video does not delete data.
   *
   * @see https://www.drupal.org/node/2927183
   */
  public function testVideoEditing() {
    $id = 10;
    $data = $this->getTestVideoData($id);
    $videoData = $this->postVideoData($data);

    $videoEditPage = 'media/' . $videoData->value . '/edit';
    $this->drupalGet($videoEditPage);
    $this->getSession()->getPage()->pressButton('Save and keep published');

    $videoEntity = $this->loadVideoEntity($videoData->value);
    $videoFieldName = $this->videoManager->videoFieldName();
    $videoField = $videoEntity->get($videoFieldName);

    $this->assertEquals($videoEntity->label(), $videoField->title);

    $this->assertEquals($data->itemData->itemID, $videoField->item_id);
    $this->assertEquals($data->itemData->title, $videoField->title);
    $this->assertEquals($data->itemData->hash, $videoField->hash);
    $this->assertEquals($data->itemData->teaser, $videoField->teaser);
    $this->assertEquals($data->itemData->uploaded, $videoField->uploaded);
    $this->assertEquals($data->itemData->copyright, $videoField->copyright);
    $this->assertEquals(
      $data->itemData->encodedTHUMBS,
      $videoField->encodedTHUMBS
    );
    $this->assertEquals($data->itemData->runtime, $videoField->runtime);
    $this->assertEquals($data->itemStates->isSSC, $videoField->isSSC);
    $this->assertEquals($data->itemStates->encodedSSC, $videoField->encodedSSC);
    $this->assertEquals(
      $data->itemStates->validfrom_ssc,
      $videoField->validfrom_ssc
    );
    $this->assertEquals(
      $data->itemStates->validto_ssc,
      $videoField->validto_ssc
    );
    $this->assertEquals(
      $data->itemStates->encodedHTML5,
      $videoField->encodedHTML5
    );
    $this->assertEquals($data->itemStates->isMOBILE, $videoField->isMOBILE);
    $this->assertEquals(
      $data->itemStates->encodedMOBILE,
      $videoField->encodedMOBILE
    );
    $this->assertEquals(
      $data->itemStates->validfrom_mobile,
      $videoField->validfrom_mobile
    );
    $this->assertEquals(
      $data->itemStates->validto_mobile,
      $videoField->validto_mobile
    );
    $this->assertEquals($data->itemStates->active, $videoField->active);
    $this->assertEquals($data->itemStates->isDeleted, $videoField->isDeleted);
    $this->assertEquals($data->itemStates->isBlocked, $videoField->isBlocked);
    $this->assertEquals($data->itemStates->encodedTHUMBS, 1);
  }

}
