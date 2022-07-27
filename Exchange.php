<?php


namespace App\Services\Redemption;

use App\Enums\RedemptionTypeEnum;
use App\Models\Client;
use App\Models\Redemption;
use App\Services\Redemption\Vtex\Api as VtexApi;
use Carbon\Carbon;

class Exchange
{
    /**
     * @var Client
     */
    protected $client;

    /**
     * @var float
     */
    protected $points;

    /**
     * @var VtexApi
     */
    protected $api;

    /**
     * @var string
     */
    private $domainPhilco = 'https://www.philcoclub.com.br';

    /**
     * @var string
     */
    private $domainBritania = 'https://www.britaniaemcasa.com.br';

    /**
     * @return Client
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * @param Client $client
     * @return Exchange
     */
    public function setClient(Client $client): self
    {
        $this->client = $client;

        return $this;
    }

    /**
     * @return float
     */
    public function getPoints(): float
    {
        return $this->points;
    }

    /**
     * @param float $points
     * @return Exchange
     */
    public function setPoints(float $points): self
    {
        $this->points = $points;

        return $this;
    }

    public function request()
    {
        $clientPoints = $this->clientPoints();

        /**
         * Verifica se o cliente tem pontos suficientes para realizar a troca
         */
        if ($this->points > $clientPoints) {
            return [
                'success' => false,
                'message' => 'Seu saldo de pontos: ' . $clientPoints . ', é menor que a quantidade solicitada para troca'
            ];
        }

        /**
         * Recupera o profileId do cliente na api
         */
        $profileId = $this->api()->verifyAccount($this->client->email);

        /**
         * Se não existir, inicia a criação
         */
        if (!$profileId) {
            $nameArray = explode(' ', $this->client->name);

            /**
             * Envia requisição de criação de conta na api
             */
            $response = $this->api()->createAccount([
                'firstName' => $nameArray[0],
                'lastName' => count($nameArray) > 1 ? end($nameArray) : '',
                'documentType' => 'cpf',
                'document' => $this->client->cpf,
                'email' => $this->client->email,
                'homePhone' => '+55' . $this->client->cellphone
            ]);

            /**
             * Se não conseguir criar
             */
            if (!$response) {
                return [
                    'success' => false,
                    'message' => 'Não foi possível criar a conta do cliente na loja'
                ];
            }

            /**
             * Após criado, busca novamento o profileId
             */
            $profileId = $this->api()->verifyAccount($this->client->email);

            /**
             * Se mesmo após criado não localizar o profileId
             */
            if (!$profileId) {
                return [
                    'success' => false,
                    'message' => 'Não foi possível localizar o cliente na loja [verifyAccount]'
                ];
            }

            /**
             * Envia requisição de criação de endereço
             */
            $address = $this->client->address;

            $this->api()->createAddress([
                'userId' => $profileId,
                'addressName' => 'Casa',
                'addressType' => 'residential',
                'street' => $address->street,
                'number' => $address->number,
                'complement' => $address->complement,
                'neighborhood' => $address->neighborhood,
                'city' => $address->city->name,
                'state' => $address->city->state->code,
                'country' => 'BRA',
                'postalCode' => $address->postal_code,
            ]);
        }

        /**
         * Recupera informação do cluster do cliente na API
         */
        $response = $this->api()->search('CL', 'id,bCluster', 'email=' . $this->client->email);

        if (!$response) {
            return [
                'success' => false,
                'message' => 'Não foi possível localizar o cliente na loja [search]'
            ];
        }

        $clientSearchResponse = $response[0];

        /**
         * Verifica se o cliente está dentro do bCluster [='M']
         * Caso não esteja, envia requisição de alteração.
         */
        if ($clientSearchResponse->bCluster != 'M') {
            $response = $this->api()->saveAccount([
                'userId' => $profileId,
                'email' => $this->client->email,
                'bCluster' => 'M'
            ]);

            if (!$response) {
                return [
                    'success' => false,
                    'message' => 'Não foi possível alterar o bCluster'
                ];
            }
        }

        /**
         * Gift Card
         */

        /**
         * Define data de expiração do cartão de presentes e pontos do cartão
         */
        $expiringDate = Carbon::today()->addDays(30)->format('Y-m-d') . 'T00:00:00.00';

        /**
         * Envia requisição para criação do Gift Card
         */
        $giftCard = $this->api()->giftCardCreate([
            'customerId' => $profileId,
            'cardName' => 'BRITZ',
            'multipleRedemptions' => true,
            'restrictedToOwner' => false,
            'multipleCredits' => true,
            'caption' => 'BRITZ',
            'expiringDate' => $expiringDate
        ]);

        /**
         * Testa dados cartão de presente
         */
        if (!$giftCard || !isset($giftCard->id)) {
            return [
                'success' => false,
                'message' => 'Não foi possível criar o cartão de presente'
            ];
        }

        /**
         * Envia requisição para inclusão de pontos no cartão de presente
         */
        $pointsResponse = $this->api()->giftCardAddPoints($giftCard->id, [
            'description' => 'Adiciona ' . ($this->points * 100) . ' Britz',
            'value' => $this->points * 100,
            'expiringDate' => $expiringDate
        ]);

        if (!$pointsResponse) {
            return [
                'success' => false,
                'message' => 'Não foi possível adicionar os pontos no cartão de presente'
            ];
        }

        /**
         * Inclui novo registro de resgate na tabela redemptions
         */

        $redemption = new Redemption();
        $redemption->client_id = $this->client->id;
        $redemption->name = $this->client->name;
        $redemption->type = RedemptionTypeEnum::STORE;
        $redemption->ip = request()->ip();
        $redemption->points = $this->points;
        $redemption->value = $this->points;
        $redemption->exchange_rate = 1;

        if ($this->client->address) {
            $address = $this->client->address;
            $redemption->address = $address->street ? $address->street . ', ' . $address->number : '';
            $redemption->neighborhood = $address->neighborhood ?: '';
            $redemption->city = $address->city->name;
            $redemption->zip_code = $address->postal_code ?: '';
        }

        $redemption->save();


        //        /**
        //         * Recupera os canais de vendas
        //         * NÃO VEJO NECESSIDADE. DEIXANDO COMENTADO PARA VER SE VAI PRECISAR
        //         */
        //        $response = $this->api()->search('UT', 'utmi_cp,bSalesChannelPHI,bSalesChannelBRI', 'bCluster=M');
        //
        //        if(!$response) {
        //            return [
        //                'success' => false,
        //                'message' => 'Não foi possível localizar os canais de vendas'
        //            ];
        //        }
        //
        //        $channels = $response[0];


        return [
            'success' => true,
            'pointsResponse' => $pointsResponse,
            'giftCard' => $giftCard,
            'links' => [
                'philcoclub' => $this->domainPhilco . '/bf-api?loginProfile=CL-' . $clientSearchResponse->id . '&BF-VTEXSC=13&ReturnUrl=' . $this->domainPhilco,
                'britaniaemcasa' => $this->domainBritania . '/bf-api?loginProfile=CL-' . $clientSearchResponse->id . '&BF-VTEXSC=14&ReturnUrl=' . $this->domainBritania
            ]
        ];
    }

    /**
     * @return float
     */
    public function clientPoints(): float
    {
        //        $data = DB::table('exchanges')
        //            ->select('value_store')
        //            ->orderBy('updated_at', 'desc')
        //            ->first();
        //
        //        $multiplier = $data ? $data->value_store : 1;
        //
        //        return round($this->client->points * $multiplier, 2);

        return ceil($this->client->points);
    }

    /**
     * @return VtexApi
     */
    public function api(): VtexApi
    {
        if ($this->api instanceof VtexApi) {
            return $this->api;
        }

        return new VtexApi();
    }
}
