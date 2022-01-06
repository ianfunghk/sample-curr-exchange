<?php

namespace Drupal\ian_curr_exchange\Plugin\rest\resource;

use Drupal\Component\Plugin\DependentPluginInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Routing\BcRoute;
use Drupal\rest\ModifiedResourceResponse;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Represents YearOverYear records as resources.
 *
 * @RestResource (
 *   id = "ian_curr_exchange_yearoveryear",
 *   label = @Translation("YearOverYear"),
 *   uri_paths = {
 *     "canonical" = "/api/ian-curr-exchange-yearoveryear",
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
class YearoveryearResource extends ResourceBase
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
   * @return ModifiedResourceResponse
   *   The response containing the record.
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
      $toCurArray = [];
    } else {
      $toCurArray = explode(',', $requestArray['toCur']);
    }

    if (empty($requestArray['fromCur'])) {
      $hasError = true;
      $errMsg .= 'fromCur param is missing. ';
      $fromCur = '';
    } else {
      $fromCur = $requestArray['fromCur'];
    }

    if (empty($requestArray['startDate'])) {
      $hasError = true;
      $errMsg .= 'startDate param is missing. ';
      $startDateStr = '';
    } else {
      $startDateStr = $requestArray['startDate'];
    }

    if (empty($requestArray['endDate'])) {
      $hasError = true;
      $errMsg .= 'endDate param is missing. ';
      $endDateStr = '';
    } else {
      $endDateStr = $requestArray['endDate'];
    }

    if (!empty($hasError)) {
      $responseArray['err_msg'] = $errMsg;
      return new ModifiedResourceResponse($responseArray, 400);
    }

//    $startDateStr = '2021-01-01';
//    $endDateStr = '2021-01-07';

    $startDateTimeObj = \DateTime::createFromFormat('Y-m-d', $startDateStr);
    $endDateTimeObj = \DateTime::createFromFormat('Y-m-d', $endDateStr);

    $loopDateTimeObj = clone $startDateTimeObj;

    $count = 0;

    $outputArray = [];

    $oneDayDateInterval = \DateInterval::createFromDateString('1 day');

    while ($loopDateTimeObj <= $endDateTimeObj && $count < 14) {
      $aDateStr = $loopDateTimeObj->format('Y-m-d');
      $outputArray[$aDateStr] = $this->getChangeDataArray($fromCur, $toCurArray, $loopDateTimeObj);
      $loopDateTimeObj->add($oneDayDateInterval);
      $count++;
    }

    $responseArray['data'] = $outputArray;

    return new ModifiedResourceResponse($responseArray, 200);
  }

  private function getChangeDataArray($fromCur, $toCurArray, $dateTimeObj = null)
  {
    // TODO: Add static cache and DB cache

    $responseArray = array();

    $oneDayDateInterval = \DateInterval::createFromDateString('1 day');


    if (empty($dateTimeObj)) {
      //Default to yesterday if not provided
      $dateTimeObj = new \DateTime();
      $dateTimeObj->sub($oneDayDateInterval);
    }

    $dateTimeObjForCalc = clone $dateTimeObj;

    $currDataArray = $this->getHistoricalRates($fromCur, $toCurArray, $dateTimeObjForCalc);
    $oneDayDateInterval = \DateInterval::createFromDateString('1 day');
    $dateTimeObjForCalc->sub($oneDayDateInterval);
    $prevDataArray = $this->getHistoricalRates($fromCur, $toCurArray, $dateTimeObjForCalc);

    $prevPrice = 0;

    if (!empty($prevDataArray) && !empty($prevDataArray['data'])
      && !empty($prevDataArray['data']->rates)) {
      foreach ($prevDataArray['data']->rates as $toCurrFromApi => $rate) {
        $prevPrice = $rate;
      }
    }

    $thatDayPrice = 0;

    if (!empty($currDataArray) && !empty($currDataArray['data'])
      && !empty($currDataArray['data']->rates)) {
      foreach ($currDataArray['data']->rates as $toCurrFromApi => $rate) {
        $thatDayPrice = $rate;
      }
    }

    if (empty($prevPrice)) {
      $percentageChange = 0;
    } else {
      $percentageChange = (($thatDayPrice - $prevPrice) / $prevPrice) * 100;
      $percentageChange = round($percentageChange,'2');
    }


    //$responseArray['prev'] = $prevDataArray;
    //$responseArray['curr'] = $currDataArray;
    $responseArray['prevPrice'] = $prevPrice;
    $responseArray['thatDayPrice'] = $thatDayPrice;
    $responseArray['percentageChange'] = $percentageChange;

    return $responseArray;
  }


  /**
   * @param string $baseCurr
   * @return array
   */
  private function getHistoricalRates(string $baseCurr = 'EUR', $toCurrArray = [], $dateTimeObj = null)
  {
    // TODO: Add static cache and DB cache

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

    if (!empty($toCurrArray)) {
      $symbolsStr = '&symbols=' . implode(",", $toCurrArray);
    } else {
      $symbolsStr = '';
    }

    if (empty($dateTimeObj)) {
      //Default to yesterday if not provided
      $dateTimeObj = new \DateTime();
      $oneDayDateInterval = \DateInterval::createFromDateString('1 day');
      $dateTimeObj->sub($oneDayDateInterval);
    }

    $dateStr = $dateTimeObj->format('Y-m-d');

    $responseArray = [];

    try {
      // http://api.exchangeratesapi.io/v1/2013-12-24?access_key=&base=EUR&symbols=USD,CAD,HKD
      $request = $this->httpClient->request('get', 'http://api.exchangeratesapi.io/v1/' . $dateStr . '?base=' . $baseCurr . '&access_key=' . $accessKey . '&format=1' . $symbolsStr);
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
