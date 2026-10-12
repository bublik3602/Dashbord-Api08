<?php
/**
 * Клиент интеграции с Zabbix API (JSON-RPC 2.0)
 * Для получения данных с весов, расходомеров, электросчетчиков и статусов оборудования
 */

require_once __DIR__ . '/helpers.php';

class ZabbixClient {
    private array $config;
    private ?string $authToken = null;
    private int $requestId = 1;

    public function __construct() {
        $fullConfig = get_config();
        $this->config = $fullConfig['zabbix'] ?? [];
        if (!empty($this->config['token'])) {
            $this->authToken = $this->config['token'];
        }
    }

    /**
     * Вызов метода Zabbix JSON-RPC API
     */
    public function request(string $method, array $params = []): array {
        if (empty($this->config['url']) || !($this->config['enabled'] ?? false)) {
            return ['error' => 'Zabbix API отключен в конфигурации'];
        }

        $payload = [
            'jsonrpc' => '2.0',
            'method'  => $method,
            'params'  => $params,
            'id'      => $this->requestId++,
        ];

        if ($this->authToken !== null && $method !== 'user.login') {
            $payload['auth'] = $this->authToken;
        }

        $ch = curl_init($this->config['url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json-rpc', 'User-Agent: Factory-Dashboard/1.0'],
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return ['error' => 'Zabbix API недоступен: ' . ($curlError ?: "HTTP {$httpCode}")];
        }

        $decoded = json_decode($response, true);
        if (isset($decoded['error'])) {
            return ['error' => $decoded['error']['data'] ?? $decoded['error']['message']];
        }

        return ['result' => $decoded['result'] ?? null];
    }

    /**
     * Авторизация через логин/пароль (для Zabbix без готового API Token)
     */
    public function authenticate(): bool {
        if (!empty($this->authToken)) {
            return true;
        }

        $user = $this->config['user'] ?? 'Admin';
        $password = $this->config['password'] ?? '';

        $res = $this->request('user.login', [
            'username' => $user,
            'password' => $password,
        ]);

        if (isset($res['result']) && is_string($res['result'])) {
            $this->authToken = $res['result'];
            return true;
        }

        return false;
    }

    /**
     * Запрос актуальных значений по списку ключей
     */
    public function getLatestValues(array $itemKeys): array {
        if (!($this->config['enabled'] ?? false)) {
            return $this->getSimulatedMetrics();
        }

        $res = $this->request('item.get', [
            'output' => ['itemid', 'key_', 'lastvalue', 'lastclock', 'name'],
            'filter' => ['key_' => array_values($itemKeys)],
        ]);

        if (isset($res['result']) && is_array($res['result'])) {
            $mapped = [];
            foreach ($res['result'] as $item) {
                $mapped[$item['key_']] = [
                    'value' => (float)$item['lastvalue'],
                    'time'  => (int)$item['lastclock'],
                    'name'  => $item['name']
                ];
            }
            return $mapped;
        }

        return $this->getSimulatedMetrics();
    }

    /**
     * Симуляция показаний датчиков при отсутствии подключения к живому Zabbix
     */
    public function getSimulatedMetrics(): array {
        return [
            'scale.seed.in.plant.total'   => ['value' => 465.80, 'name' => 'Ваги приймання насіння'],
            'scale.seed.in.prod.total'    => ['value' => 452.00, 'name' => 'Ваги подачі у виробництво'],
            'production.oil.yield.tonnes' => ['value' => 198.80, 'name' => 'Вихід олії'],
            'production.meal.yield.tonnes'=> ['value' => 185.30, 'name' => 'Вихід шроту'],
            'production.husk.yield.tonnes'=> ['value' => 62.40,  'name' => 'Вихід лузги'],
            'power.meter.main.kw'         => ['value' => 3940.0, 'name' => 'Общая мощность ввода'],
            'water.meter.feed.flow.rate'  => ['value' => 58.9,   'name' => 'Расход воды технической'],
            'equipment.cleaner.status'    => ['value' => 1,      'name' => 'Статус сепаратора'],
            'equipment.dryer.status'      => ['value' => 1,      'name' => 'Статус сушилки'],
            'equipment.dehuller.status'   => ['value' => 1,      'name' => 'Статус рушально-веечного цеха'],
            'equipment.press_line.status' => ['value' => 1,      'name' => 'Статус прессов'],
            'equipment.extraction.status' => ['value' => 1,      'name' => 'Статус экстракции'],
            'equipment.boiler.status'     => ['value' => 1,      'name' => 'Статус парового котла'],
        ];
    }
}
