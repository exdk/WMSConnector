<?php

namespace App\Helper;

use App\Models\Company;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;

/**
 * Класс для работы с 1С WMS
 * @author yunusov
 *
 */
class WmsCaller
{
    /**
     * Клиент GuzzleHttp
     * @var object
     */
    private object $client;

    /**
     * Параметры авторизации в 1С
     * @var array
     */
    private array $auth;

    /**
     * Типы методов, передача которым выполняется строго в JSON
     * @var array
     */
    private array $jsonMethods = ['post', 'put'];

    /**
     * Создание инстанса контроллера.
     *
     * @return void
     */
    public function __construct()
    {
        $this->client = new Client(['base_uri' => (string)env('WMS_BASE_URI')]);
        $this->auth = [env('WMS_USERNAME'), env('WMS_PASSWORD'), 'ntlm'];
    }

    /**
     * Запрос получения данных из WMS
     * Выполняется попытка получение ответа пять раз с паузами (0.2, 0.3, 0.4 и 0.5 сек.) - затем отваливаемся с ошибкой.
     * Реализовано для устранения периодической ошибки 401 по протоколу ntlm.
     * Для WMS было достаточно три попытки, для УТ (GET /carbrands) добавлены еще две.
     *
     * @param string $method - метод (get, post и т.д.)
     * @param string $path - путь API 1C ('depositors', 'company' и т.д.)
     * @param array $options - дополнительные параметры (id и т.д)
     * @return mixed
     */
    private function request(string $method, string $path, array $options = []): mixed
    {
        $param = in_array($method, $this->jsonMethods) ? 'json' : 'query';
        $options = array_merge(['auth' => $this->auth], [$param => $options]);
        try {
            $data = $this->call($method, $path, $options);
        } catch (BadResponseException) {
            try {
                $data = $this->call($method, $path, $options, 2);
            } catch (BadResponseException) {
                try {
                    $data = $this->call($method, $path, $options, 3);
                } catch (BadResponseException) {
                    try {
                        $data = $this->call($method, $path, $options, 6);
                    } catch (BadResponseException) {
                        $data = $this->call($method, $path, $options, 10);
                    }
                }
            }
        }
        return json_decode($data, true);
    }

    /**
     * Вызов метода 1С
     *
     * @param string $method - метод (get, post и т.д.)
     * @param string $path - путь API 1C ('depositors', 'company' и т.д.)
     * @param array $options - дополнительные параметры (id и т.д)
     * @param int $attempt - номер попытки вызова
     * @return string
     */
    private function call(string $method, string $path, array $options, int $attempt = 0): string
    {
        if ($attempt) {
            usleep($attempt * 100000);
        }
        $response = $this->client->{$method}($path, $options);
        return $response->getBody()->getContents();
    }

    /**
     * Получение списка собственных организаций
     * Непосредственно со складом взаимодействует { "id": "900e0763-515d-11e9-88ea-68b599cc4ea2", "inn": "5074116672", "name": "ООО \"НКН-Строй\"", "code": "000000003" }
     * @return mixed
     */
    public function getOwnOrganizations(): mixed
    {
        return $this->request('get', 'companies');
    }

    /**
     * Получение списка компаний
     * @return mixed
     */
    public function getCompaniesList(): mixed
    {
        return $this->request('get', 'depositors');
    }

    /**
     * Получение подробной информации о компании с сохранением несуществующих в БД компаний-контрагентов
     * @param string $companyId
     * @param bool $supplier
     * @return mixed
     */
    public function getCompanyInfo(string $companyId, bool $supplier = false): mixed
    {
        if ($supplier) {
            $supplierInfo = Company::whereWmsId($companyId)->first();
            if ($supplierInfo) {
                $companyInfo = $supplierInfo;
            } else {
                $companyInfo = $this->request('post', 'company', ['id' => $companyId]);
                Company::create($companyInfo['Code']['value'], $companyId, $companyInfo, true);
            }
        } else {
            $companyInfo = $this->request('post', 'company', ['id' => $companyId]);
        }
        return $companyInfo;
    }

    /**
     * Получение списка адресов для рассылок
     * @param string $companyId
     * @return mixed
     */
    public function getMailingAddressesList(string $companyId): mixed
    {
        return $this->request('get', 'mailing_addresses', ['id' => $companyId]);
    }

    /**
     * Обновление списка адресов для рассылок
     * @param string $companyId
     * @param string $mailingId
     * @param string $addresses
     * @return mixed
     */
    public function putMailingAddresses(string $companyId, string $mailingId, string $addresses): mixed
    {
        return $this->request('put', 'mailing_addresses', [
            'depositorId' => $companyId,
            'mailingId'   => $mailingId,
            'addresses'   => $addresses,
        ]);
    }

    /**
     * Получение шаблона заявки по файлу CSV
     * @param string $depositorId
     * @param string $organizationId
     * @param object|string $file
     * @param string $orderType
     * @param string $options
     * @param string|null $fileName = null
     * @return mixed
     */
    public function postOrdersTemplateFromCsv(string $depositorId, string $organizationId, object|string $file, string $orderType, string $options, string $fileName = null): mixed
    {
        if (gettype($file) == 'object') {
            $fileContent = file_get_contents($file->path());
        } else {
            $fileContent = $file;
        }
        if ($fileName == null) {
            $fileName = $file->getClientOriginalName();
        }
        $requestParams = [
            'depositorId'    => $depositorId,
            'organizationId' => $organizationId,
            'fileName'       => $fileName,
            'file'           => base64_encode($fileContent),
        ];
        if ($orderType == 'arrivals') {
            $requestParams['receiptType'] = $options;
        } else {
            $requestParams['shippingDirection'] = $options;
        }
        return $this->request('post', $orderType . '_template_from_csv', $requestParams);
    }

    /**
     * Создание новой заявки
     * @param string $orderType
     * @param array $arrivalData
     * @return mixed
     */
    public function postOrder(string $orderType, array $arrivalData): mixed
    {
        return $this->request('post', $orderType . '_store', $arrivalData);
    }

    /**
     * Изменение заявки
     * @param string $orderType
     * @param array $validatedData
     * @return mixed
     */
    public function putOrder(string $orderType, array $validatedData): mixed
    {
        return $this->request('put', $orderType, $validatedData);
    }

    /**
     * Получение списка кодов номенклатуры компании
     * @param string $companyId
     * @return mixed
     */
    public function getNomenclatureList(string $companyId): mixed
    {
        return $this->request('get', 'nomenclature_depositor_list', ['id' => $companyId]);
    }

    /**
     * Получение информации о номенклатуре
     * @param array $nomenclatureId
     * @return mixed
     */
    public function getNomenclatureInfo(array $nomenclatureId): mixed
    {
        return $this->request('post', 'nomenclature', ['id' => $nomenclatureId]);
    }

    /**
     * Получение остатков номенклатуры
     * @param array $nomenclatureIds
     * @return mixed
     */
    public function getNomenclatureRemains(array $nomenclatureIds): mixed
    {
        return $this->request('post', 'nomenclature_remains', ['id' => $nomenclatureIds]);
    }

    /**
     * Получение полей справочника
     * @param string $referenceName
     * @return mixed
     */
    public function getReference(string $referenceName): mixed
    {
        return $this->request('get', 'reference/' . $referenceName);
    }

    /**
     * Получение списка заказов с указанием типа и статуса
     * @param string $orderType
     * @param string $depositorId
     * @param string $startDate
     * @param string $endDate
     * @param string|null $orderStatus
     * @return mixed
     */
    public function getOrdersList(string $orderType, string $depositorId, string $startDate, string $endDate, string $orderStatus = null): mixed
    {
        $requestParams = [
            'depositorId'   => $depositorId,
            'startDate'     => $startDate,
            'endDate'       => $endDate,
        ];
        if ($orderStatus) {
            $requestParams['fieldFilter'] = [
                'status' => [
                    $orderStatus
                ]
            ];
        }
        return $this->request('post', $orderType, $requestParams);
    }

    /**
     * Получение данных заказов
     * @param string $method
     * @param array $ordersIds
     * @param bool $detailed
     * @return mixed
     */
    public function getOrdersInfo(string $method, array $ordersIds, bool $detailed = false): mixed
    {
        return $this->request('post', $method, ['id' => $ordersIds, 'detailed' => $detailed]);
    }

    /**
     * Получение списка рейсов.
     * @param string $startDate
     * @param string $endDate
     * @param array $depositorId
     * @return array
     */
    public function getFlightsList(string $startDate, string $endDate, array $depositorId): array
    {

        $requestParams = [
            'startDate'     => $startDate,
            'endDate'       => $endDate,
            'fieldFilter'   => [
                'depositor' => $depositorId
            ]
        ];
        If (!isset($depositorId[0])) {
            $requestParams = [
                'startDate'     => $startDate,
                'endDate'       => $endDate,
            ];
        }
        return $this->request('post', 'flights_list', $requestParams);
    }

    /**
     * Получение информации о рейсах.
     * @param array $flightId
     * @return array
     */
    public function getFlightsInfo(array $flightId): array
    {
        $requestParams = [
            'id'   => $flightId,
        ];
        return $this->request('post', 'flights', $requestParams);
    }

    /**
     * Добавление рейса.
     * @param array $flightData
     * @return array
     */
    public function postFlight(array $flightData): array
    {
        return $this->request('post', 'flights_store', $flightData);
    }

    /**
     * Изменение рейса
     * @param array $flightData
     * @return mixed
     */
    public function putFlight(array $flightData): mixed
    {
        return $this->request('put', 'flights', $flightData);
    }

    /**
     * Получение списка транспортных пропусков
     * @param string $startDate
     * @param string $endDate
     * @param array|null $depositorId
     * @return mixed
     */
    public function getPassagesList(string $startDate, string $endDate, array $depositorId = null): mixed
    {
        $requestParams = [
            'startDate'     => $startDate,
            'endDate'       => $endDate,
            'fieldFilter'   => [
                'depositor' => $depositorId
            ]
        ];
        if ($depositorId === null) {
            $requestParams = [
                'startDate'     => $startDate,
                'endDate'       => $endDate,
            ];
        }
        return $this->request('post', 'passages_list', $requestParams);
    }

    /**
     * Получение данных транспортных пропусков
     * @param array $passagesIds
     * @return mixed
     */
    public function getPassagesInfo(array $passagesIds): mixed
    {
        return $this->request('post', 'passages', ['id' => $passagesIds]);
    }

    /**
     * Добавление пропуска.
     * @param array $passageData
     * @return array
     */
    public function postPassage(array $passageData): array
    {
        $requestParams = $passageData;
        return $this->request('post', 'passages_store', $requestParams);
    }

    /**
     * Изменение пропуска
     * @param array $passageData
     * @return mixed
     */
    public function putPassage(array $passageData): mixed
    {
        return $this->request('put', 'passages', $passageData);
    }

    /**
     * Получение актуального перечня марок автомобилей
     * @return mixed
     */
    public function getCarBrands(): mixed
    {
        return $this->request('get', 'carbrands');
    }

    /**
     * Получение данных о серийных номерах номенклатуры
     * @param string $startDate
     * @param string $endDate
     * @param string $depositorId
     * @return mixed
    */
    public function getSerialNumbersReport(string $startDate, string $endDate, string $depositorId): mixed
    {
        $requestParams = [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'depositorId' => $depositorId
        ];
        return $this->request('post', 'report_serialnumbers', $requestParams);
    }

    /**
     * Получение списка возвратов
     * @param string $startDate
     * @param string $endDate
     * @param array|null $depositorId
     * @return mixed
     */
    public function getRefundsList(string $startDate, string $endDate, array $depositorId = null): mixed
    {
        $requestParams = [
            'startDate'     => $startDate,
            'endDate'       => $endDate,
            'fieldFilter'   => [
                'depositor' => $depositorId
            ]
        ];
        if ($depositorId === null) {
            $requestParams = [
                'startDate'     => $startDate,
                'endDate'       => $endDate,
            ];
        }
        return $this->request('post', 'refunds_list', $requestParams);
    }

    /**
     * Получение данных транспортных пропусков
     * @param array $refundsIds
     * @return mixed
     */
    public function getRefundsInfo(array $refundsIds): mixed
    {
        return $this->request('post', 'refunds', ['id' => $refundsIds]);
    }
}
