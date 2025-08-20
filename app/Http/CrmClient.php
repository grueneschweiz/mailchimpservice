<?php


namespace App\Http;


use App\OAuthClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

class CrmClient
{
    private $clientId;
    private $clientSecret;
    private $token;
    private $guzzle;

    /**
     * CrmClient constructor
     *
     * @param int $clientId the oAuth2 client credentials id
     * @param string $clientSecret the oAuth2 client credentials secret
     * @param string $apiUrl the base url of the api
     */
    public function __construct(int $clientId, string $clientSecret, string $apiUrl)
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->guzzle = new Client(['base_uri' => $this->forceSSL($apiUrl)]);

        $this->loadToken();
    }

    /**
     * Make sure the given url is always using httpS
     *
     * @param string $url
     *
     * @return string
     */
    private function forceSSL(string $url)
    {
        $url = preg_replace('/^\/\//', 'https://', $url);
        $url = preg_replace('/^http:\/\//', 'https://', $url);

        return $url;
    }

    /**
     * Get the oauth token of this client
     */
    private function loadToken()
    {
        $client = OAuthClient::find($this->clientId);

        if (!$client) {
            $client = $this->addClient();
        }

        $this->token = $client->token;

        if (!$this->isTokenValid()) {
            $this->refreshToken($client);
        }
    }

    /**
     * Add new client
     *
     * @return OAuthClient
     */
    private function addClient(): OAuthClient
    {
        $client = new OAuthClient();

        $client->client_id = $this->clientId;
        $client->client_secret = $this->clientSecret;
        $client->save();

        return $client;
    }

    /**
     * Test the current token against the api to chekc if it is (still) valid
     *
     * @return bool
     *
     * @throws GuzzleException
     */
    private function isTokenValid(): bool
    {
        try {
            $this->get('/api/v1/auth');

            return true;
        } catch (ClientException $e) {
            return false;
        }
    }

    /**
     * Get
     *
     * @param string $relativeUrl
     *
     * @return ResponseInterface
     *
     * @throws GuzzleException
     */
    public function get(string $relativeUrl)
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ];

        return $this->guzzle->get($relativeUrl, ['headers' => $headers]);
    }

    /**
     * Get a fresh access token
     *
     * @param OAuthClient $client
     *
     * @throws GuzzleException
     */
    private function refreshToken(OAuthClient $client)
    {
        $res = $this->guzzle->post(
            '/oauth/token',
            [
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope' => '',
                ]
            ]
        );

        $body = json_decode((string)$res->getBody());
        $this->token = $body->access_token;

        $client->token = $this->token;
        $client->save();
    }

    /**
     * Upsert
     *
     * @param string $relativeUrl
     * @param array $data
     *
     * @return ResponseInterface
     *
     * @throws GuzzleException
     */
    public function post(string $relativeUrl, array $data)
    {
        return $this->guzzle->post($relativeUrl, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/json',
            ],
            'json' => $data,
            'allow_redirects' => false,
        ]);
    }

    /**
     * Update
     *
     * @param string $relativeUrl
     * @param array $data
     *
     * @return ResponseInterface
     *
     * @throws GuzzleException
     */
    public function put(string $relativeUrl, array $data)
    {
        return $this->guzzle->put($relativeUrl, [
            'headers' => ['Authorization' => 'Bearer ' . $this->token],
            'body' => json_encode($data)
        ]);
    }
}
