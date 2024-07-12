<?php

class QueryBuilder
{
    /**
     * @var array
     */
    public $queryComponents;

    /**
     * @var \cDBMSSQL|\cPDOMSSQL
     */
    private $db;

    /**
     * @var \PDO
     */
    private $pdo;

    /**
     * capiQueryBuilder constructor
     *
     * @param $connectTo
     */
    public function __construct($connectTo = null)
    {
        $this->setDb(cFactory::getDB($connectTo));

        $this->getDb()->connect();

        //region Получение PDO и его настройка
        $this->pdo = $this->getDb()->getDriverObject();
        //endregion
    }

    /**
     * Сбрасывает объект в изначальное состояние.
     * Используется в начале нового запроса.
     * Автоматически вызывается при установке компонента table.
     */
    public function reset() : QueryBuilder
    {
        $this->queryComponents = [
            'sql' => null,
            'table' => null,
            'alias' => null,
            'select' => [
                'fields'   => '*',
                'distinct' => false,
            ],
            'limit'  => 100,
            'offset' => 0,
        ];

        return $this;
    }

    /**
     * @param \Closure $closure
     *
     * @return mixed
     * @throws \Exception
     */
    public function transaction(\Closure $closure)
    {
        try {
            // Стартуем транзакцию
            $this->pdo->beginTransaction();

            // Выполняем тело транзакции
            $closureResult = $closure($this);

            // Если всё прошло без ошибок, завершаем транзакцию.
            $this->pdo->commit();

            return $closureResult;
        } catch (\Exception $e) {
            // Если тело транзакции выполнилось с ошибками, откатываем транзакцию.
            $this->pdo->rollBack();

            throw $e;
        }
    }

    /**
     * Установка параметров кеширования запроса.
     * Подробнее в описании метода cDataBase::cache().
     *
     * @param int|callable $cacheTime Время жизни кеша
     * @param array|string $params Параметры кеширования
     *
     * @return QueryBuilder
     */
    public function cache($cacheTime = 3600, $params = null): QueryBuilder
    {
        //region Проверяем не отключено ли кеширование в конфиге
        $cacheEnabled = filter_var(
            (cFactory::$conf['cache_enabled'] ?? true),
            FILTER_VALIDATE_BOOLEAN
        );

        if(! $cacheEnabled) {
            return $this;
        }
        //endregion

        $this->getDb()->cache($cacheTime, $params);

        return $this;
    }

    //region Компоненты запроса

    /**
     * @param string $sql
     *
     * @return $this
     */
    public function query(string $sql): QueryBuilder
    {
        $this->queryComponents['sql'] = $sql;

        return $this;
    }

    /**
     * @param array|string $fields
     * @param bool         $overwrite Если true, добавленные ранее поля компонента select будут перезаписаны.
     *                                Если false, поля добавятся к существующим.
     *
     * @return $this
     */
    public function select($fields = [], bool $overwrite = false) : QueryBuilder
    {
        $fields = is_string($fields) ? [$fields] : $fields;

        //region Применяем псевдоним таблицы к полям, если он есть
        if (! is_null($this->queryComponents['alias'])) {
            foreach ($fields as &$field) {
                if (mb_strpos($field, '.') === false) {
                    $field = $this->queryComponents['alias'] . '.' . $field;
                }
            }

            unset($field);
        }
        //endregion

        if ($this->queryComponents['select']['fields'] === '*') {
            $this->queryComponents['select']['fields'] = [];
        }

        if (! $overwrite) {
            $this->queryComponents['select']['fields'] = array_unique(
                array_merge(
                    $this->queryComponents['select']['fields'],
                    $fields
                )
            );
        } else {
            $this->queryComponents['select']['fields'] = $fields;
        }

        return $this;
    }

    /**
     * @param array|string $fields
     * @param bool         $overwrite
     *
     * @return $this|\QueryBuilder
     */
    public function selectDistinct($fields = [], bool $overwrite = false) : QueryBuilder
    {
        $this->queryComponents['select']['distinct'] = true;

        return $this->select($fields, $overwrite);
    }

    /**
     * @param string      $tableName Имя таблицы
     * @param string|null $alias     Псевдоним таблицы
     *
     * @return $this
     */
    public function table(string $tableName, string $alias = null) : QueryBuilder
    {
        // Вызывается для установки дефолтных значений
        $this->reset();

        $this->queryComponents['table'] = $tableName;

        if ($alias) {
            $this->queryComponents['alias'] = $alias;
        }

        return $this;
    }

    /**
     * @param string $tableName
     * @param string $key
     * @param string $value
     * @param string $operator
     * @param string $type Возможные значения: INNER, LEFT, LEFT OUTER, RIGHT, RIGHT OUTER, FULL, FULL OUTER, CROSS
     *
     * @return $this
     */
    public function join(
        string $tableName,
        string $key,
        string $value,
        string $operator = '=',
        string $type = ''
    ) : QueryBuilder {
        $this->queryComponents['join'][] = [
            'table'    => $tableName,
            'key'      => $key,
            'value'    => $value,
            'operator' => $operator,
            'type'     => $type,
        ];

        return $this;
    }

    /**
     * @param array $values
     *
     * @return $this
     */
    public function orderBy(array $values = []) : QueryBuilder
    {
        $res = '';

        $index = 0;

        foreach ($values as $k => $v) {
            if ($index > 0) {
                $res .= ', ';
            }

            //region Применяем псевдоним таблицы к ключу условия
            if (
                ! is_null($this->queryComponents['alias'])
                && mb_strpos($k, '.') === false
            ) {
                $k = $this->queryComponents['alias'] . '.' . $k;
            }
            //endregion

            $res .= "{$k} {$v}";

            $index++;
        }

        $this->queryComponents['orderBy'] = $res;

        return $this;
    }

    /**
     * @param array $values
     *
     * @return $this
     */
    public function groupBy(array $values = []) : QueryBuilder
    {
        //region Применяем псевдоним таблицы к полям
        if (! is_null($this->queryComponents['alias'])) {
            foreach ($values as &$value) {
                if (mb_strpos($value, '.') === false) {
                    $value = $this->queryComponents['alias'] . '.' . $value;
                }
            }

            unset($value);
        }
        //endregion

        $this->queryComponents['groupBy'] = $values;

        return $this;
    }

    /**
     * @param int      $limit Передайте 0, чтобы убрать лимит
     * @param int|null $offset
     *
     * @return $this
     */
    public function limit(int $limit, ?int $offset = null) : QueryBuilder
    {
        $this->queryComponents['limit']  = $limit;

        if (! is_null($offset)) {
            $this->queryComponents['offset'] = $offset;
        }

        return $this;
    }

    /**
     * @param int $offset
     *
     * @return $this
     */
    public function offset(int $offset) : QueryBuilder
    {
        $this->queryComponents['offset'] = $offset;

        return $this;
    }

    //region Where
    public function where($key, $value = null, string $operator = '=', $logicalOperator = 'AND') : QueryBuilder
    {
        if (is_string($key)) {
            //region Применяем псевдоним таблицы к ключу условия
            if (
                ! is_null($this->queryComponents['alias'])
                && mb_strpos($key, '.') === false
            ) {
                $key = $this->queryComponents['alias'] . '.' . $key;
            }
            //endregion

            $this->queryComponents['where'][] = [$key, $value, $operator, $logicalOperator];
        }

        if (is_array($key)) {
            foreach ($key as $row) {
                //region Применяем псевдоним таблицы к ключу условия
                if (
                    ! is_null($this->queryComponents['alias'])
                    && mb_strpos($row[0], '.') === false
                ) {
                    $row[0] = $this->queryComponents['alias'] . '.' . $row[0];
                }
                //endregion

                $this->queryComponents['where'][] = [$row[0], $row[1] ?? null, $row[2] ?? '=', $row[3] ?? 'AND'];
            }
        }

        return $this;
    }

    public function orWhere(string $key, $value, string $operator = '=') : QueryBuilder
    {
        $this->queryComponents['where'][] = [$key, $value, $operator, 'OR'];

        return $this;
    }

    public function whereNot(string $key, $value, string $operator = '=') : QueryBuilder
    {
        $this->queryComponents['where'][] = [$key, $value, $operator, 'AND NOT'];

        return $this;
    }

    public function orWhereNot(string $key, $value, string $operator = '=') : QueryBuilder
    {
        $this->queryComponents['where'][] = [$key, $value, $operator, 'OR NOT'];

        return $this;
    }

    public function whereIn(string $key, $value) : QueryBuilder
    {
        $this->queryComponents['where'][] = [$key, $value, 'IN', 'AND'];

        return $this;
    }

    public function whereNotIn(string $key, $value) : QueryBuilder
    {
        $this->queryComponents['where'][] = [$key, $value, 'NOT IN', 'AND'];

        return $this;
    }

    public function orWhereIn(string $key, $value) : QueryBuilder
    {
        $this->queryComponents['where'][] = [$key, $value, 'IN', 'OR'];

        return $this;
    }

    public function orWhereNotIn(string $key, $value) : QueryBuilder
    {
        $this->queryComponents['where'][] = [$key, $value, 'NOT IN', 'OR'];

        return $this;
    }

    public function whereBetween(string $key, $valueFrom, $valueTo) : QueryBuilder
    {
        $this->queryComponents['where'][] = [$key, [$valueFrom, $valueTo], 'BETWEEN', 'AND'];

        return $this;
    }

    public function orWhereBetween(string $key, $valueFrom, $valueTo) : QueryBuilder
    {
        $this->queryComponents['where'][] = [$key, [$valueFrom, $valueTo], 'BETWEEN', 'OR'];

        return $this;
    }

    public function whereNull(string $key) : QueryBuilder
    {
        $this->queryComponents['where'][] = [$key, null, '=', 'AND'];

        return $this;
    }

    public function whereNotNull(string $key) : QueryBuilder
    {
        $this->queryComponents['where'][] = [$key, null, '!=', 'AND'];

        return $this;
    }

    public function orWhereNull(string $key) : QueryBuilder
    {
        $this->queryComponents['where'][] = [$key, null, '=', 'OR'];

        return $this;
    }

    public function orWhereNotNull(string $key) : QueryBuilder
    {
        $this->queryComponents['where'][] = [$key, null, '!=', 'OR'];

        return $this;
    }
    //endregion
    //endregion

    //region Groups
    public function andGroupStart() : QueryBuilder
    {
        $this->queryComponents['where'][] = ['(', '', '', 'AND'];

        return $this;
    }

    public function orGroupStart() : QueryBuilder
    {
        $this->queryComponents['where'][] = ['(', '', '', 'OR'];

        return $this;
    }

    public function notGroupStart() : QueryBuilder
    {
        $this->queryComponents['where'][] = ['(', '', '', 'AND NOT'];

        return $this;
    }

    public function orNotGroupStart() : QueryBuilder
    {
        $this->queryComponents['where'][] = ['(', '', '', 'OR NOT'];

        return $this;
    }

    public function groupEnd() : QueryBuilder
    {
        $this->queryComponents['where'][] = [')', '', '', ''];

        return $this;
    }
    //endregion

    //region Запросы

    //region Get
    /**
     * @param        $value
     * @param string $fieldName
     *
     * @return array|null
     * @throws \Exception
     */
    public function find($value, string $fieldName = 'id') : ?array
    {
        $this->where($fieldName, $value);

        return $this->first();
    }

    /**
     * Запрос на получение записей.
     *
     * По умолчанию, возвращает результаты в виде массива объектов.
     *
     * @return array
     * @throws \Exception
     */
    public function get() : array
    {
        $sql = $this->buildQuerySelect();

        $callAddress = $this->getCallAddress();

        return $this->getDb()->getRows($sql, $callAddress['file'], $callAddress['line']) ?? [];
    }

    /**
     * Возвращает первый результат запроса в виде объекта
     *
     * @return array|null
     * @throws \Exception
     */
    public function first() : ?array
    {
        $sql = $this->buildQuerySelect();

        $callAddress = $this->getCallAddress();

        return $this->getDb()->getRow($sql, $callAddress['file'], $callAddress['line']);
    }

    /**
     * Возвращает массив из значений одной колонки по её названию.
     *
     * @param string|null $columnName Название колонки.
     *                                Если не указана, берётся первая в выборке.
     *
     * @return array
     * @throws \Exception
     */
    public function column(string $columnName = null) : ?array
    {
        //region Определяем индекс колонки
        $columnIndex = 0;

        if ($columnName) {
            $columnIndex = array_search($columnName, $this->queryComponents['select']);
        }
        //endregion

        $sql = $this->buildQuerySelect();

        $callAddress = $this->getCallAddress();

        return $this->getDb()->getColumn($sql, $callAddress['file'], $callAddress['line']) ?? [];
    }

    /**
     * @throws \Exception
     */
    public function count() : ?int
    {
        $sql = $this->buildQueryCount();

        $callAddress = $this->getCallAddress();

        return $this->getDb()->executeScalar($sql, $callAddress['file'], $callAddress['line']) ?? 0;
    }

    /**
     * @return bool
     * @throws \Exception
     */
    public function exists(): bool
    {
        $sql = $this
            ->limit(1, 0)
            ->buildQueryExists()
        ;

        $callAddress = $this->getCallAddress();

        return (bool)$this->getDb()->executeScalar($sql, $callAddress['file'], $callAddress['line']);
    }
    //endregion

    //region Raw SQL
    /**
     * Возвращает сформированный sql запрос
     *
     * @return string
     * @throws \Exception
     */
    public function selectSql() : string
    {
        return $this->buildQuerySelect();
    }
    //endregion

    /**
     * Запрос на добавление записи.
     *
     * @param array|null $insertData Список [Название поля -> значение]
     *
     * @return string|null
     * @throws \Exception
     */
    public function insert(array $insertData = null) : ?string
    {
        if (empty($this->queryComponents['table'])) {
            throw new Exception('Не задан компонент запроса table');
        }

        $this->queryComponents['insertData'] = $insertData;

        $callAddress = $this->getCallAddress();

        $result = $this->getDb()->insert(
            $this->queryComponents['table'],
            $this->queryComponents['insertData'],
            $callAddress['file'],
            $callAddress['line'],
            false,
            true
        );

        return (string)$result ?: null;
    }

    /**
     * Запрос на обновление записи.
     *
     * @param array|null $updateData Список [Название поля -> новое значение]
     * @param bool       $ignoreWhere
     *
     * @return bool
     * @throws \Exception
     */
    public function update(array $updateData = null, bool $ignoreWhere = false) : bool
    {
        if (empty($this->queryComponents['table'])) {
            throw new Exception('Не задан компонент запроса table');
        }

        if (
            ! $ignoreWhere
            && empty($this->queryComponents['where'])
        ) {
            throw new Exception('Не задан компонент запроса where. Чтобы разрешить запросу изменение всех строк таблицы, явно укажите это с помощью параметра $ignoreWhere');
        }

        $this->queryComponents['updateData'] = $updateData;


        $sql = $this->buildQueryUpdate($ignoreWhere);

        $callAddress = $this->getCallAddress();

        return (bool)$this->getDb()->query($sql, $callAddress['file'], $callAddress['line']);
    }

    /**
     * Запрос на удаление записи.
     *
     * @return bool
     * @throws \Exception
     */
    public function delete() : bool
    {
        if (empty($this->queryComponents['where'])) {
            throw new Exception('Не задан компонент запроса where. Удаление всех записей таблицы запрещено.');
        }

        $sql = $this->buildQueryDelete();

        $callAddress = $this->getCallAddress();

        return (bool)$this->getDb()->query($sql, $callAddress['file'], $callAddress['line']);
    }
    //endregion

    //region Getters/Setters
    /**
     * @return \cDBMSSQL|\cPDOMSSQL
     */
    public function getDb()
    {
        return $this->db;
    }

    /**
     * @param \cDBMSSQL|\cPDOMSSQL $db
     */
    public function setDb($db) : void
    {
        $this->db = $db;
    }

    /**
     * @return \PDO
     */
    public function getPdo() : PDO
    {
        return $this->pdo;
    }

    /**
     * @param \PDO $pdo
     */
    public function setPdo(PDO $pdo) : void
    {
        $this->pdo = $pdo;
    }
    //endregion

    //region Private

    //region Build
    /**
     * Формирует запрос в виде строки
     *
     * @return string
     * @throws \Exception
     */
    private function buildQuerySelect() : string
    {
        if (! is_null($this->queryComponents['sql'])) {
            return $this->queryComponents['sql'];
        }

        //region SELECT
        $selectFieldsString   = implode(',', $this->queryComponents['select']['fields']);
        $selectDistinctString = $this->queryComponents['select']['distinct'] ? 'DISTINCT' : '';

        $selectFrom = $this->queryComponents['table']
            . ' '
            . ($this->queryComponents['alias'] ?? '')
        ;

        $sql = "
            SELECT {$selectDistinctString} {$selectFieldsString}
            FROM {$selectFrom} WITH(NOLOCK)
        ";
        //endregion

        //region JOIN
        foreach ($this->queryComponents['join'] as $join) {
            //region Применяем псевдоним таблицы к обоим операндам условия
            if (
                ! is_null($this->queryComponents['alias'])
            ) {
                if (mb_strpos($join['key'], '.') === false) {
                    $join['key'] = $this->queryComponents['alias'] . '.' . $join['key'];
                }

                if (mb_strpos($join['value'], '.') === false) {
                    $join['value'] = $this->queryComponents['alias'] . '.' . $join['value'];
                }
            }
            //endregion

            $sql .= "
                {$join['type']} JOIN {$join['table']} WITH(NOLOCK)
                ON {$join['key']} {$join['operator']} {$join['value']}
            ";
        }
        //endregion

        //region WHERE
        if (! empty($this->queryComponents['where'])) {
            $whereString = $this->getWhereAsString();

            if (! empty($whereString)) {
                $sql .= "
                    WHERE {$whereString}
                ";
            }
        }
        //endregion

        //region GROUP BY
        if (! empty($this->queryComponents['groupBy'])) {
            $groupByString = implode(',', $this->queryComponents['groupBy']);

            $sql .= "
                GROUP BY {$groupByString}
            ";
        }
        //endregion

        //region ORDER BY
        $orderBy = $this->queryComponents['orderBy'] ?? $this->queryComponents['select']['fields'][0];

        $sql .= "
                ORDER BY {$orderBy}
            ";
        //endregion

        //region LIMIT/OFFSET
        if (! empty($this->queryComponents['limit'])) {
            $sql .= "
                OFFSET {$this->queryComponents['offset']} ROWS 
                FETCH FIRST {$this->queryComponents['limit']} ROWS ONLY
            ";
        }
        //endregion

        return $sql;
    }

    /**
     * @return string
     */
    private function buildQueryCount() : string
    {
        if (! is_null($this->queryComponents['sql'])) {
            return $this->queryComponents['sql'];
        }

        $selectFrom = $this->queryComponents['table']
            . ' '
            . ($this->queryComponents['alias'] ?? '')
        ;

        $sql = "
            SELECT COUNT(*)
            FROM {$selectFrom}
        ";

        foreach ($this->queryComponents['join'] as $join) {
            $sql .= "
                {$join['type']} JOIN {$join['table']} WITH(NOLOCK)
                ON {$join['key']} {$join['operator']} {$join['value']}
            ";
        }

        if (isset($this->queryComponents['where'])) {
            $whereString = $this->getWhereAsString();

            $sql .= "
                WHERE {$whereString}
            ";
        }

        return $sql;
    }

    /**
     * @return string
     * @throws \Exception
     */
    private function buildQueryExists(): string
    {
        $selectQuery = $this->buildQuerySelect();

        return "IF EXISTS ({$selectQuery}) SELECT 1 as result";
    }

    /**
     * @return string
     */
    private function buildQueryInsert() : string
    {
        $table = $this->queryComponents['table'];
        $fields = implode(', ', array_keys($this->queryComponents['insertData']));
        $values = implode(', ', $this->queryComponents['insertData']);

        return 'INSERT INTO '
            . $this->queryComponents['table']
            . ' (' . implode(', ', array_keys($this->queryComponents['insertData'])) . ')'
            . ' VALUES(' . implode(', ', $this->queryComponents['insertData']) . ')';
    }

    /**
     * @param bool $ignoreWhere
     *
     * @return string
     */
    private function buildQueryUpdate(bool $ignoreWhere = false) : string
    {
        $setString = '';

        $index = 0;
        foreach ($this->queryComponents['updateData'] as $key => $value) {
            if ($index > 0) {
                $setString .= ",";
            }

            $setString .= "{$key} = " . $this->getDb()->getQueryValue($value, true);

            $index++;
        }

        $updateTable = $this->queryComponents['table']
            . ' '
            . ($this->queryComponents['alias'] ?? '')
        ;

        $sql = "
            UPDATE {$updateTable}
            SET {$setString}
        ";

        if (
            ! $ignoreWhere
            && ! empty($this->queryComponents['where'])
        ) {
            $whereString = $this->getWhereAsString();

            $sql .= " 
                WHERE {$whereString} 
            ";
        }

        return $sql;
    }

    /**
     * @return string
     */
    private function buildQueryDelete() : string
    {
        $whereString = $this->getWhereAsString();

        $deleteTable = $this->queryComponents['table']
            . ' '
            . ($this->queryComponents['alias'] ?? '')
        ;

        return "
            DELETE FROM {$deleteTable}
            WHERE {$whereString}
        ";
    }
    //endregion

    /**
     * @return mixed|string
     */
    private function getWhereAsString()
    {
        $whereString = '';

        foreach ($this->queryComponents['where'] as $index => [$key, $value, $operator, $logicalOperator]) {

            //region Если в условие IN передан пустой массив, используем заведомо ложное условие
            if (
                $operator === 'IN'
                && empty($value)
            ) {
                $key = 0;
                $operator = '=';
                $value = 1;
            }
            //endregion

            if (
                $index === 0
                // Если предыдущий элемент был началом группы условий
                || (
                    isset($this->queryComponents['where'][$index - 1])
                    && $this->queryComponents['where'][$index - 1][0] === '('
                )
            ) {
                $logicalOperator = '';
            }

            if (is_null($value)) {
                $operator = $operator === '=' ? 'IS NULL' : 'IS NOT NULL';
                $value    = null;
            }

            if (is_array($value)) {
                $valuesString = '';

                foreach ($value as $i => $v) {
                    if ($i > 0) {
                        $valuesString .= ',';
                    }

                    $valuesString .= is_int($v) ? $v : "'{$v}'";
                }

                $operator .= " ({$valuesString})";
                $value    = null;
            }

            if ($operator === 'BETWEEN') {
                $operator = "'{$value[0]}' BETWEEN '{$value[1]}'";
                $value    = null;
            }

            $whereString .= " {$logicalOperator} {$key} {$operator} ";

            if (
                $value !== ''
                && ! is_null($value)
            ) {
                $whereString .= is_string($value) ? " '{$value}' " : " {$value} ";
            }
        }

        return $whereString;
    }

    /**
     * @return array
     */
    private function getCallAddress() : array
    {
        $bt = debug_backtrace()[0];

        return [
            'file' => $bt['file'],
            'line' => $bt['line']
        ];
    }
    //endregion
}
