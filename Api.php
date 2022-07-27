<?php


namespace App\Services\Redemption\Vtex;

use GuzzleHttp\Client as GuzzleHttpClient;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Api
{
    private $credentials = [
        'account' => 'philco',
        'key' => '',
        'token' => '',
        'environment' => '',
    ];

    /**
     * @var GuzzleHttpClient
     */
    private $httpClient;

    /**
     * @param string $email
     * @return bool
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function verifyAccount($email)
    {
        $response = $this->request('GET', 'https://profilesystem.vtex.com.br/api/profile-system/pvt/profiles/sk-', [
            'query' => [
                'surrogateKey' => $email,
                'an' => $this->credentials['account']
            ]
        ]);

        if ($response) {
            return json_decode($response->getBody())->profileId;
        }

        return false;
    }

    /**
     * @param string $acronym
     * @param string $fields
     * @param string $where
     * @return bool|mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function search(string $acronym, string $fields, string $where)
    {
        $response = $this->request('GET', 'http://api.vtex.com/{accountName}/dataentities/' . $acronym . '/search', [
            'query' => [
                '_fields' => $fields,
                '_where' => $where
            ]
        ]);

        if ($response) {
            return json_decode($response->getBody());
        }

        return false;
    }

    /**
     * @param array $data
     * @return bool|mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function createAccount($data)
    {
        $data['accountName'] = $this->credentials['account'];
        $data['dataEntityId'] = 'CL';
        $data['bCluster'] = 'M';

        $response = $this->request('POST', 'http://api.vtex.com/{accountName}/dataentities/CL/documents', [
            'json' => $data,
            'headers' => [
                'Accept' => 'application/vnd.vtex.ds.v10+json'
            ]
        ]);

        if ($response) {
            return json_decode($response->getBody());
        }

        return false;
    }

    /**
     * @param array $data
     * @return bool|mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function createAddress($data)
    {
        $response = $this->request('POST', 'http://api.vtex.com/{accountName}/dataentities/AD/documents', [
            'json' => $data,
            'headers' => [
                'Accept' => 'application/vnd.vtex.ds.v10+json'
            ]
        ]);

        if ($response) {
            return json_decode($response->getBody());
        }

        return false;
    }

    /**
     * @param array $data
     * @return bool|mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function saveAccount(array $data)
    {
        $response = $this->request('PATCH', 'http://{accountName}.vtexcommercestable.com.br/api/dataentities/CL/documents', [
            'json' => $data,
            'headers' => [
                'Accept' => 'application/vnd.vtex.ds.v10+json'
            ]
        ]);

        if ($response) {
            return json_decode($response->getBody());
        }

        return false;
    }

    /**
     * @param array $data
     * @return bool|mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function giftCardCreate(array $data)
    {
        $response = $this->request('POST', 'http://{accountName}.vtexcommercestable.com.br/api/gift-card-system/pvt/giftCards', [
            'json' => $data,
            'headers' => [
                'Accept' => 'application/vnd.vtex.ds.v10+json'
            ]
        ]);

        if ($response) {
            return json_decode($response->getBody());
        }

        return false;
    }

    /**
     * @param int $id
     * @param array $data
     * @return bool|mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function giftCardAddPoints(int $id, array $data)
    {
        $response = $this->request('POST', 'http://{accountName}.vtexcommercestable.com.br/api/gift-card-system/pvt/giftCards/' . $id . '/credit', [
            'json' => $data,
            'headers' => [
                'Accept' => 'application/vnd.vtex.ds.v10+json'
            ]
        ]);

        if ($response) {
            return json_decode($response->getBody());
        }

        return false;
    }

    /**
     * @param $method
     * @param $url
     * @param array $options
     * @return \Psr\Http\Message\ResponseInterface
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function request($method, $url, $options = [])
    {
        $url = (string)Str::of($url)
            ->replace('{accountName}', $this->credentials['account']);

        if (!isset($options['timeout'])) {
            $options['timeout'] = 30;
        }

        if (!isset($options['headers'])) {
            $options['headers'] = [];
        }

        $options['headers']['x-vtex-api-appKey'] = $this->credentials['key'];;
        $options['headers']['x-vtex-api-appToken'] = $this->credentials['token'];


        try {
            return $this->httpClient()->request($method, $url, $options);
        } catch (RequestException  $e) {
            abort($e->getMessage());
            Log::error('Ocorreu um erro durante uma consulta de e-mail na VTEX. Message: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * @return GuzzleHttpClient
     */
    private function httpClient()
    {
        if ($this->httpClient instanceof GuzzleHttpClient) {
            return $this->httpClient;
        }

        return new GuzzleHttpClient();
    }
}
