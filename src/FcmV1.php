<?php

namespace Edujugon\PushNotification;

use Carbon\Carbon;
use Edujugon\PushNotification\Fcm;
use Exception;
use Google\Client as GoogleClient;
use Google\Service\FirebaseCloudMessaging;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class FcmV1 extends Fcm
{
    const CACHE_SECONDS = 55 * 60; // 55 minutes

    /**
     * Number of concurrent requests to multiplex in the same connection.
     *
     * @var int
     */
    protected $concurrentRequests = 10;

    protected $unregisteredDeviceTokens = [];

    protected $feedbacks = [];

    /**
     * Fcm constructor.
     * Override parent constructor.
     */
    public function __construct()
    {
        $this->config = $this->initializeConfig('fcmv1');

        $this->url = 'https://fcm.googleapis.com/v1/projects/' . $this->config['projectId'] . '/messages:send';

        $this->client = new Client($this->config['guzzle'] ?? []);

        $this->concurrentRequests = $this->config['concurrentRequests'] ?? 10;
    }

    /**
     * Set the apiKey for the notification
     * @param string $apiKey
     */
    public function setApiKey($apiKey)
    {
        throw new Exception('Not available on FCM V1');
    }

    /**
     * Set the projectId for the notification
     * @param string $projectId
     */
    public function setProjectId($projectId)
    {
        $this->config['projectId'] = $projectId;

        $this->url = 'https://fcm.googleapis.com/v1/projects/' . $this->config['projectId'] . '/messages:send';
    }

    /**
     * Set the jsonFile path for the notification
     * @param string $jsonFile
     */
    public function setJsonFile($jsonFile)
    {
        $this->config['jsonFile'] = $jsonFile;
    }

    /**
     * Update the values by key on config array from the passed array. If any key doesn't exist, it's added.
     * @param array $config
     */
    public function setConfig(array $config)
    {
        parent::setConfig($config);

        // Update url
        $this->setProjectId($this->config['projectId']);
    }

    /**
     * Set the needed headers for the push notification.
     *
     * @return array
     */
    protected function addRequestHeaders()
    {
         Log::info("OAuth Token: " . $this->getOauthToken());
        return [
            'Authorization' => 'Bearer ' . $this->getOauthToken(),
            'Content-Type' =>  'application/json',
        ];
    }

    /**
     * Send Push Notification
     *
     * @param  array $deviceTokens
     * @param array $message
     *
     * @return \stdClass  GCM Response
     */
    public function send(array $deviceTokens, array $message)
    {
        // FCM v1 only sends one device at a time
        $headers = $this->addRequestHeaders();
        $this->feedbacks = [];
        $this->unregisteredDeviceTokens = [];
        $requests = [];
    
        foreach ($deviceTokens as $deviceToken) {
            $jsonData = ['message' => $this->buildMessage($message)];
            $jsonData['message']['token'] = $deviceToken;
            $body = json_encode($jsonData);
    
            $requests[$deviceToken] = new Request('POST', $this->url, $headers, $body);
        }
    
        $pool = new Pool($this->client, $requests, [
            'concurrency' => $this->concurrentRequests,
            'fulfilled' => function (GuzzleResponse $response, $deviceToken) {
                $responseBody = json_decode($response->getBody(), true);
                Log::info("FCM Success for token $deviceToken: " . json_encode($responseBody));


                $this->feedbacks[$deviceToken] = [
                    'success' => true,
                    'response' => $responseBody,
                ];
            },
            'rejected' => function (RequestException $reason, $deviceToken) {
                $error = json_decode($reason->getResponse()->getBody(), true);
                Log::error("FCM Error for token $deviceToken: " . json_encode($error));
                $this->feedbacks[$deviceToken] = [
                    'success' => false,
                    'error' => $error,
                ];
    
                // Log Error
                \Log::error("FCM Error for token $deviceToken: " . json_encode($error));
    
                if (isset($error['error']['code']) && $error['error']['code'] === 404) {
                    $this->unregisteredDeviceTokens[] = $deviceToken;
                }
            },
        ]);
        //Log::info("Request Body: " . $body);
        //Log::info("FCM URL: " . $this->url);
        Log::info('Payload FCM:', $message);
        Log::info('FCM Response:', $this->feedbacks);


        $promise = $pool->promise();
        $promise->wait();
    
        $this->setFeedback($this->feedbacks);
    }

    /**
     * Provide the unregistered tokens of the sent notification.
     *
     * @param array $devices_token
     * @return array $tokenUnRegistered
     */
    public function getUnregisteredDeviceTokens(array $devices_token)
    {
        return $this->unregisteredDeviceTokens;
    }

    /**
     * Prepare the data to be sent
     *
     * @param $topic
     * @param $message
     * @param $isCondition
     * @return array
     */
    protected function buildData($topic, $message, $isCondition)
    {
        $condition = $isCondition ? ['condition' => $topic] : ['topic' => '/topics/'.$topic];

        return [
            'message' => array_merge($condition, $this->buildMessage($message)),
        ];
    }

    protected function getOauthToken()
    {
       
        return Cache::remember(
            Str::slug('fcm-v1-oauth-token-' . $this->config['projectId']),
            self::CACHE_SECONDS,
            function () {
                $jsonFilePath = $this->config['jsonFile'];
                $googleClient = new GoogleClient();
                $googleClient->setAuthConfig($jsonFilePath);
                $googleClient->addScope(FirebaseCloudMessaging::FIREBASE_MESSAGING);
                $accessToken = $googleClient->fetchAccessTokenWithAssertion();
                return $accessToken['access_token'] ?? null;
            }
        );
    }
}