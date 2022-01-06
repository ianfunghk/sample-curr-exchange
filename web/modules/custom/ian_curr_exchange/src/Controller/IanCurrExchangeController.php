<?php

namespace Drupal\ian_curr_exchange\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Returns responses for ian_curr_exchange routes.
 */
class IanCurrExchangeController extends ControllerBase {

  /**
   * Builds the response.
   */
  public function build() {

    $build['content'] = [
      '#type' => 'item',
      '#markup' => $this->t('It works!'),
    ];

    return $build;
  }

}
