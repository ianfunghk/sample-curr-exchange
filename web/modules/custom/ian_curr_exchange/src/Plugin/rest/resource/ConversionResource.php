<?php

namespace Drupal\ian_curr_exchange\Plugin\rest\resource;

use Drupal\Component\Plugin\DependentPluginInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Routing\BcRoute;
use Drupal\rest\ModifiedResourceResponse;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Represents Conversion records as resources.
 *
 * @RestResource (
 *   id = "ian_curr_exchange_conversion",
 *   label = @Translation("Conversion"),
 *   uri_paths = {
 *     "canonical" = "/api/curr-exchange-conversion",
 *   }
 * )
 *
 * @DCG
 * This plugin exposes database records as REST resources. In order to enable it
 * import the resource configuration into active configuration storage. You may
 * find an example of such configuration in the following file:
 * core/modules/rest/config/optional/rest.resource.entity.node.yml.
 * Alternatively you can make use of REST UI module.
 * @see https://www.drupal.org/project/restui
 * For accessing Drupal entities through REST interface use
 * \Drupal\rest\Plugin\rest\resource\EntityResource plugin.
 */
class ConversionResource extends ResourceBase
{

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $dbConnection;

  /**
   * Guzzle\Client instance.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Constructs a Drupal\rest\Plugin\rest\resource\EntityResource object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param array $serializer_formats
   *   The available serialization formats.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\Core\Database\Connection $db_connection
   *   The database connection.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, array $serializer_formats, LoggerInterface $logger, Connection $db_connection, ClientInterface $http_client)
  {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->dbConnection = $db_connection;
    $this->httpClient = $http_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
  {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('rest'),
      $container->get('database'),
      $container->get('http_client')
    );
  }

  /**
   * Responds to GET requests.
   *
   * @param int $id
   *   The ID of the record.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   */
  public function get()
  {
    $hasError = false;
    $errMsg = '';

    $responseArray = [];

    \Drupal::request()->query->all();

    /** @var \Symfony\Component\HttpFoundation\Request $request */
    $request = \Drupal::request();
    /** @var \Drupal\Core\Http\InputBag $query */
    $query = $request->query;

    $requestArray = $query->all();

    if (empty($requestArray['toCur'])) {
      $hasError = true;
      $errMsg .= 'toCur param is missing. ';
      $toCur = '';
    } else {
      $toCur = $requestArray['toCur'];
    }

    if (empty($requestArray['fromCur'])) {
      $hasError = true;
      $errMsg .= 'fromCur param is missing. ';
      $fromCur = '';
    } else {
      $fromCur = $requestArray['fromCur'];
    }

    if (empty($requestArray['value'])) {
      $hasError = true;
      $errMsg .= 'value param is missing. ';
      $value = 0;
    } else {
      $value = $requestArray['value'];
    }

    if (!empty($hasError)) {
      $responseArray['err_msg'] = $errMsg;
      return new ModifiedResourceResponse($responseArray, 400);
    }


    $exchangeRatesArray = $this->getExchangeRates($fromCur);

    if (!empty($exchangeRatesArray)
      && !empty($exchangeRatesArray['data'])
      && !empty($exchangeRatesArray['data']->rates)
    ) {
      $rates = $exchangeRatesArray['data']->rates;
    } else {
      $rates = [];
    }

    if (empty($rates)) {
      $responseArray['err_msg'] = 'Fail to get rate from exchangeratesapi.io API';
      return new ModifiedResourceResponse($responseArray, 400);
    }

    $thisRate = 0;

    foreach ($rates as $toCurrFromApi => $rate) {
      if ($toCurrFromApi == $toCur) {
        $thisRate = $rate;
      }
    }

    if (empty($thisRate)) {
      $responseArray['err_msg'] = 'Fail to get rate from exchangeratesapi.io API';
      return new ModifiedResourceResponse($responseArray, 400);
    }

    $returnValue = $value * $thisRate;
    $returnValue = round($returnValue, 2);

    $responseArray['success'] = true;
    $responseArray['value'] = $returnValue;

    //return new ResourceResponse($this->loadRecord($id));
    //return new ModifiedResourceResponse($responseArray, 200);
    return new ModifiedResourceResponse($responseArray, 200);
  }

  /**
   * @param string $baseCurr
   * @return array
   */
  private function getExchangeRates(string $baseCurr = 'EUR')
  {

    /** @var \Drupal\Core\Config\ConfigFactory $configFactory */
    $configFactory = \Drupal::configFactory();

    /** @var \Drupal\Core\Config\ImmutableConfig $config */
    $config = $configFactory->get('ian_curr_exchange.settings');

    $accessKey = $config->get('access_key');

    if (empty($accessKey)) {
      $responseArray['err_msg'] = 'API access key not set';
      return $responseArray;
    }

    if (empty($baseCurr)) {
      $baseCurr = 'EUR';
    }

    $responseArray = [];

    try {
      $request = $this->httpClient->request('get', 'http://api.exchangeratesapi.io/v1/latest?base=' . $baseCurr . '&access_key=' . $accessKey . '&format=1');
      $responseStr = $request->getBody();
      $responseJsonArray = json_decode($responseStr);
      $responseArray['data'] = $responseJsonArray;

    } catch (GuzzleException $e) {
      \Drupal::logger('ian_curr_exchange')->error('GuzzleException:' . $e->getMessage() . ' (ERR_CODE:1641129600)' . ' (' . __FILE__ . ':' . __LINE__ . ')');
      $responseArray['err_msg'] = 'GuzzleException. Please contact admin.';
    } catch (\Exception $e) {
      \Drupal::logger('ian_curr_exchange')->error('Exception (' . get_class($e) . '):' . $e->getMessage() . ' (ERR_CODE:1641129601)' . ' (' . __FILE__ . ':' . __LINE__ . ')');
      $responseArray['err_msg'] = 'Exception (' . get_class($e) . '). Please contact admin.';
    }

    return $responseArray;
  }


}
