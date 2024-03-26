<?php

namespace App\Http\Controllers;

use AmoCRM\Exceptions\AmoCRMoAuthApiException;
use AmoCRM\Models\CustomFieldsValues\NumericCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\NumericCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueModels\NumericCustomFieldValueModel;
use AmoCRM\Client\AmoCRMApiClient;
use AmoCRM\Exceptions\AmoCRMApiException;
use Carbon\Carbon;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use League\OAuth2\Client\Token\AccessToken;
use Illuminate\Http\JsonResponse;

class AmoCRMController extends Controller
{
    private Carbon $lastRequestTime;
    private int $requestCount = 0;
    private int $requestLimit = 1000;

    public function __construct()
    {
        $this->lastRequestTime = Carbon::now();
    }

    /**
     * @throws AmoCRMoAuthApiException
     * @throws AmoCRMApiException
     */
    public function calculateProfit()
    {
        if ($this->checkApiLimits()) {
            return response()->json(['error' => 'Превышен лимит запросов к API'], 429);
        }
        $amo = new AmoCRMApiClient();
        $accessToken = new AccessToken([
            'access_token' => getenv('AMOCRM_ACCES_TOKEN'),
            'token_type' => getenv('AMOCRM_TOKEN_TYPE'),
            'expires' => time() + 3600
        ]);
        $amo->setAccessToken($accessToken);
        $amo->setAccountBaseDomain('tesdsdsdst.amocrm.ru');

        try {
            $leadsService = $amo->leads();
            $leadsCollection = $leadsService->get();
        } catch (AmoCRMApiException $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        $profitFieldId = 104541;
        $costFieldId = 104491;

        $totalLeadsProcessed = 0;
        $totalLeadsUpdated = 0;

        foreach ($leadsCollection as $lead) {
            Log::info('Processing lead: ' . $lead->getId());
            $customFields = $lead->getCustomFieldsValues();
            Log::info("customFields: $customFields");
            if ($customFields == null) {
                $this->patchLeadCost($lead->getId());
            }
            $budgetValue = $lead->getPrice() ?? -1;
            $costValue = $this->getFieldValueById($customFields, $costFieldId);
            $budget = $budgetValue !== -1 ? $budgetValue : 0;

            $cost = $costValue !== null ? intval($costValue->getValue()) : 0;
            Log::info("cost: $cost");
            $profit = $budget - $cost;
            $this->patchLeadProfit($lead->getId(), $profit);
            Log::info("PROFIT: $profit");

            $profitCustomField = new NumericCustomFieldValuesModel();
            $profitCustomField->setFieldId($profitFieldId);

            $profitValueModel = new NumericCustomFieldValueModel();
            $profitValueModel->setValue(strval($profit));

            $profitValueCollection = new NumericCustomFieldValueCollection();
            $profitValueCollection->add($profitValueModel);

            $profitCustomField->setValues($profitValueCollection);

            $leadToUpdate = $leadsService->getOne($lead->getId());
            $leadCustomFields = $leadToUpdate->getCustomFieldsValues();

            foreach ($leadCustomFields as $leadCustomField) {
                if ($leadCustomField->getFieldId() === $profitFieldId) {
                    $leadCustomField->setValues($profitCustomField->getValues());
                    break;
                }
            }

            $leadToUpdate->setCustomFieldsValues($leadCustomFields);

            try {
                $leadsService->updateOne($leadToUpdate);
                $totalLeadsUpdated++;
            } catch (AmoCRMApiException $e) {
                $validationErrors = $e->getValidationErrors();

                Log::error('Ошибка в обнвлении лида: ' . $e->getMessage() . '. Ошибка валидации: ' . json_encode($validationErrors));

                return response()->json(['error' => 'Ошибка в обнвлении лида: ' . $e->getMessage() . '. Ошибка валидации: ' . json_encode($validationErrors)], 500);
            }
            $totalLeadsProcessed++;
            Log::info("Total leads processed: $totalLeadsProcessed");
            Log::info("Total leads updated: $totalLeadsUpdated");
        }
        return response()->json(['success' => 'Success'], 200);
    }

    /**
     * Отправляет PATCH-запрос для обновления значения поля "Прибыль" в сделке
     * @param int $leadId ID сделки
     * @param int $profit Новое значение прибыли
     * @throws GuzzleException
     */
    private function patchLeadProfit($leadId, $profit)
    {
        $client = new Client();
        $url = 'https://tesdsdsdst.amocrm.ru/api/v4/leads/' . $leadId;

        $body = [
            'custom_fields_values' => [
                [
                    'field_id' => 104541,
                    'values' => [
                        [
                            'value' => strval($profit)
                        ]
                    ]
                ]
            ]
        ];

        $response = $client->request('PATCH', $url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . getenv('AMOCRM_ACCES_TOKEN')
            ],
            'json' => $body
        ]);
        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            Log::error('Ошибка при отправке PATCHзапроса для обновления поля "Прибыль" в сделке с ID ' . $leadId);
            throw new Exception('Ошибка при отправке PATCH-запроса');
        }
        $this->requestCount++;
        $this->lastRequestTime = Carbon::now();
    }

    /**
     * Отправляет PATCH-запрос для обновления значения поля "Себестоимость" в сделке
     * @param int $leadId ID сделки
     * @throws GuzzleException
     */
    private function patchLeadCost($leadId)
    {
        $client = new Client();
        $url = 'https://tesdsdsdst.amocrm.ru/api/v4/leads/' . $leadId;

        $body = [
            'custom_fields_values' => [
                [
                    'field_id' => 104491,
                    'values' => [
                        [
                            'value' => strval(0)
                        ]
                    ]
                ]
            ]
        ];

        $response = $client->request('PATCH', $url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . getenv('AMOCRM_ACCES_TOKEN')
            ],
            'json' => $body
        ]);
        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            Log::error('Ошибка при отправке PATCHзапроса для обновления поля "Прибыль" в сделке с ID ' . $leadId);
            throw new Exception('Ошибка при отправке PATCH-запроса');
        }
        $this->requestCount++;
        $this->lastRequestTime = Carbon::now();
    }

    private function checkApiLimits()
    {
        $currentTime = Carbon::now();
        if ($this->requestCount >= $this->requestLimit) {
            $elapsedTime = $currentTime->diffInSeconds($this->lastRequestTime);
            if ($elapsedTime < 3600) {
                return response()->json(['error' => 'Превышен лимит запросов к API'], 429);
            }

            $this->requestCount = 0;
            $this->lastRequestTime = $currentTime;

            return true;
        }

        return false;
    }

    private function getFieldValueById($customFields, $fieldId)
    {
        if ($customFields !== null) {
            foreach ($customFields as $field) {
                if ($field->getFieldId() == $fieldId) {
                    return $field->getValues()[0];
                }
            }
        }
        return null;
    }
}
