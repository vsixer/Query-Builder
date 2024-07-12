# Query Builder

Простой построитель запросов, созданный для одного из проектов, т.к. готовое решение там применить было нельзя по ряду причин.

ВНИМАНИЕ!!! Код нуждается в доработке для использования в других проектах, залит сюда только в качестве примера.


## Содержание
- [Запрос](#запрос)
- [Компоненты запроса](#компоненты-запроса)
    - [table()](#table)
    - [select()](#select)
    - [selectDistinct()](#select-distinct)
    - [limit()](#limit)
    - [offset()](#offset)
    - [orderBy()](#order-by)
    - [groupBy()](#group-by)
- [Условия запроса](#условия-запроса)
    - [where()](#where)
    - [orWhere()](#or-where)
    - [whereNot()](#where-not)
    - [orWhereNot()](#or-where-not)
    - [whereIn()](#where-in)
    - [orWhereIn()](#or-where-in)
    - [whereNotIn()](#where-not-in)
    - [orWhereNotIn()](#or-where-not-in)
    - [whereNull()](#where-null)
    - [orWhereNull()](#or-where-null)
    - [whereNotNull()](#where-not-null)
    - [orWhereNotNull()](#or-where-not-null)
    - [whereBetween()](#where-between)
    - [orWhereBetween()](#or-where-between)
- [Группы условий](#группы-условий)
    - [groupStart()](#group-start)
    - [orGroupStart()](#or-group-start)
    - [notGroupStart()](#not-group-start)
    - [orNotGroupStart()](#or-not-group-start)
    - [groupEnd()](#group-end)
- [JOIN](#join)
- [Исполняющие методы](#исполняющие-методы)
- [Получение данных](#получение-данных)
    - [get()](#get)
    - [getArray()](#get-array)
    - [find()](#find)
    - [first()](#first)
    - [firstArray()](#first-array)
    - [column()](#column)
    - [count()](#count)
- [Добавление данных](#добавление-данных)
    - [insertData()](#insert-data)
    - [insert()](#insert)
- [Обновление данных](#обновление-данных)
    - [updateData()](#update-data)
    - [update()](#update)
- [Удаление данных](#удаление-данных)
    - [delete()](#delete)
- [Транзакции](#транзакции)
- [Прочие методы](#прочие-методы)
    - [getRawSql()](#get-raw-sql)
    - [reset()](#reset)

## Запрос

Начало запроса определяется вызовом метода `table()`. Этот запрос сбрасывает все параметры к значениям по-умолчанию. Если не вызвать `table()` между двумя запросами, возможны коллизии параметров.
Сброс параметров так же автоматически осуществляется при вызове метода `query()`.

**Пример запроса**

```PHP
try {
    $result = $this->qb
        ->table('table')
        ->select(['table.field1', 'table.field2'])
        ->join(
            'joinTable jt',
            'table.id',
            'jt.table_id'
        )
        ->where('table.field3', 3)
        ->orWhere('jt.field1', 'value', '!=')
        ->orderBy(['table.field1' => 'desc'])
        ->groupStart()
            ->whereIn('table.field4', [1, 2, 3])
            ->orWhereNull('jt.table2')
        ->groupEnd()
        ->limit(10)
        ->get();
} catch (Exception $e) {
    ...
}
```
В результате будет сформирован и выполнен следующий запрос:
```SQL
SELECT table.field1,table.field2
FROM table WITH(NOLOCK)
JOIN joinTable jt WITH(NOLOCK)
ON table.id = jt.table_id
WHERE table.field3 = 3 OR jt.field1 != 'value' AND (table.field4 IN (1,2,3) OR jt.table2 IS NULL)  
ORDER BY table.field1 desc
OFFSET 0 ROWS 
FETCH FIRST 10 ROWS ONLY
```

## Компоненты запроса

Для разных запросов требуются разные компоненты. Разработчик сам определяет какие компоненты войдут в запрос. Все компоненты можно указывать по отдельности для большей гибкости.

Все компоненты возвращают ссылку на объект QueryBuilder, так что их можно выстраивать в цепочку в любой последовательности. Например:
```PHP
$this->qb->table(...)->select(...)->where(...)->join(...)->...
```

### Table

Указать таблицу для запроса можно с помощью метода `table()`. В качестве аргумента
принимает имя таблицы. Вторым аргументом возможно указание псевдонима таблицы.
```PHP
table(string 'tableName', string 'alias');
```

Псевдоним будет автоматически подставляться в качестве префикса ко всем полям не имеющими
такого префикса. Например:
```PHP
qb
    ->table('table', 't')
    ->select(['id', 'field1', 't.field2'])
    ->where('id', 1)
    ->get()

//Результат
// SELECT t.id, t.field1, t.field2 FROM table t WHERE t.id = 1
```
То же относится и к полям таких компонентов как select, join, orderBy, groupBy.


### Select
Метод `select()` в качестве аргумента принимает массив имён полей, которые необходимо получить с помощью `SELECT` запроса

```PHP
select(array('table.field1', 'joinTable.field2'));
```

### Select Distinct
Тоже что и `select()`, но позволяет выбрать только уникальные значения.
```PHP
selectDistinct(array('table.field1', 'joinTable.field2'));
```

### Limit
Позволяет установить лимит выборки. В качестве аргументов принимает два параметра limit и offset.

**ВНИМАНИЕ!!!** Если установлен лимит, обязательно должен быть указан и порядок сортировки с помощью `orderBy()`.

```PHP
limit(int 100[, int 20]);
```

### Offset
Параметр offset можно указать и отдельно с помощью метода `offset()`.

```PHP
offset(int 10);
```

### Order By
Порядок сортировки при получении данных. Принимает на вход ассоциативный массив, где ключ - поле сортировка, а значение - порядок сортировки.

```PHP
orderBy(array('field' => 'desc'));
```

### Group By
Предложение GROUP BY используется для определения групп выходных строк, к которым могут применяться агрегатные функции (COUNT, MIN, MAX, AVG и SUM).

```PHP
groupBy(array('field1', 'field2'));
```


## Условия запроса

### Where
Базовый метод условия. Принимает 4 аргумента:
- string|array `$field` Имя поля. Может так же включать имя таблицы через точку. Вместо строки может быть передан массив условий. Например:
    ```PHP
    [
        ['field1', 'value1', '=', 'AND'],
        ['field2', 'value2', '!=', 'OR'],
        ...
    ]
    ```
- string `$value` Значение поля.
- string `$operator` Оператор условия. Может быть любым валидным оператором, например: `=, !=, >, <, LIKE`. По умолчанию равно `=`.
- string `$logicalOperator` Логический оператор перед условием. Если в запросе несколько условий, они объединяются с помощью логических операторов, таких как `AND` и `OR`. Оператор первого условия не учитывается и может быть любым.

```PHP
where(string|array $field, string $value[, string $operator = '=', string $logicalOperator = 'AND']);
```

### Or Where
То же, что и where, но с логическим оператором `OR`.
```PHP
orWhere(string $field, string $value[, string $operator = '=']);
```

### Where Not
То же, что и where, но с логическим оператором `AND NOT`.
```PHP
whereNot(string $field, string $value[, string $operator = '=']);
```
### Or Where Not
То же, что и where, но с логическим оператором `OR NOT`.
```PHP
orWhereNot(string $field, string $value[, string $operator = '=']);
```

### Where In
Используется для запросов вида `WHERE field IN (v1, v2, v3)`.

- Оператор принимает значение `IN`
- Логический оператор `AND`
- В качестве значения принимает одномерный массив.
```PHP
whereIn(string $field, array $value);
```
### Or Where In
То же, что и whereIn, но логический оператор принимает значение `OR`
```PHP
orWhereIn(string $field, array $value);
```

### Where Not In
То же, что и whereIn, но логический оператор принимает значение `AND NOT`
```PHP
whereNotIn(string $field, array $value);
```

### Or Where Not In
То же, что и whereIn, но логический оператор принимает значение `OR NOT`
```PHP
orWhereNotIn(string $field, array $value);
```

### Where Null
Принимает на вход только имя поля и сравнивает его с NULL.
- Логический оператор `AND`
```PHP
whereNull(string $field);
```

### Or Where Null
То же, что и whereNull, но логический оператор принимает значение `OR`
```PHP
orWhereNull(string $field);
```

### Where Not Null
То же, что и whereNull, но логический оператор принимает значение `AND NOT`
```PHP
whereNotNull(string $field);
```

### Or Where Not Null
То же, что и whereNull, но логический оператор принимает значение `OR NOT`
```PHP
orWhereNotNull(string $field);
```

### Where Between
Вместо одного значения принимает на вход два и формирует следующий запрос: `$valueFrom BETWEEN $valueTo`.
Логический оператор `AND`.
```PHP
whereBetween(string $field, string|int $valueFrom, string|int $valueTo);
```

### Or Where Between
То же, что и whereBetween, но с логическим оператором `OR`.
```PHP
orWhereBetween(string $field, string|int $valueFrom, string|int $valueTo);
```

## Группы условий
В SQL запросах, условия можно группировать с помощью круглых скобок. Для построения таких запросов в QueryBuilder предусмотрены конструкции, описанные в этом разделе.

Пример:
```PHP
$this->qb
->table(...)
->where(...)
->groupStart()
    ->where(...)
    ->whereBetween(...)
->groupEnd()
->orGroupStart()
    ->whereNull(...)
    ->orWhereNotNull(...)
    ->notGroupStart()
        ->where(...)
        ->orWhere(...)
        ->whereIn(...)
    ->groupEnd()
->groupEnd()
->get()
```

Допускается произвольная вложенность групп условий. Необходимо только следить за тем чтобы на каждый открывающий метод, приходился свой метод groupEnd().

### Group Start
Указывает на начало группы условий. Трансформируется в `AND (`. Аргументов не имеет.

### Or Group Start
То же, что и `groupStart()`, но с логическим оператором `OR`.

### Not Group Start
То же, что и `groupStart()`, но с логическим оператором `AND NOT`.

### Or Not Group Start
То же, что и `groupStart()`, но с логическим оператором `OR NOT`.

### Group End
Указывает на конец группы условий. Трансформируется в `)`. Аргументов не имеет.


## JOIN
Для присоединения таблиц в запросах, используется метод join. На вход он принимает следующие параметры:
- string `$tableName` Аналогичен параметру, принимаемому методом `table()`. Может включать в себя псевдоним таблицы.
- Параметры: string `$key`, string `$value` и string `$operator`, образуют собой условие подключения таблицы, которое выглядит следующим образом: `ON $key $operator $value`. Параметр `$operator` по-умолчанию равен `=`.
- string `$type` Тип присоединения таблиц. По умолчанию используется обычный `JOIN`. Возможные значения: `INNER, LEFT, LEFT OUTER, RIGHT, RIGHT OUTER, FULL, FULL OUTER, CROSS`.

```PHP
join(string $tableName,string $key,string $value[,string $operator = '=',string $type = '']) : QueryBuilder
```

В запросе может использоваться произвольное кол-во методов `join()`.

## Исполняющие методы
Эти методы используются в конце цепочки формирования компонентов запроса и выполняют его. В отличие от компонентов, исполняющие методы возвращают, либо результат, либо пустоту. По этому продолжать цепочку после из вызова не получится.

После вызова исполняющего метода, можно начинать новый запрос(вызвав метод `table()`), либо вызывать другой исполняющий метод, который выполнится с тем же набором компонентов. Например, с одним и тем же набором компонентов, можно сначала получить общее кол-во записей, затем установить лимит и получить сами записи:

```PHP
$this-qb
->table(...)
->where(...)
->count();

$this->qb
->limit(...)
->get(...);
```

## Получение данных
Методы получения данных выполняют `SELECT` запросы и возвращают результат.

### Get
Выполняет запрос и возвращает данные в виде набора ассоциативных массивов. Применяется если в результате ожидается больше одной записи.
```PHP
get(): stdClass[]
```

### First
Используется для получения только первой записи в результате. Возвращает данные в виде ассоциативного массива.
```PHP
first(): stdClass
```

### Find
То же, что и `first()`, но принимает на вход значение `$value` и имя поля `$fieldName`(по-умолчанию = `id`). Таким образом, метод `find`, удобен для получения записи по уникальному идентификатору без предварительного использования метода `where()`.

Возвращает данные в виде ассоциативного массива.
```PHP
find(string|int $value[, string $fieldName = 'id']) : ?stdClass
```

### Column
Формирует и возвращает массив из данных только одного, конкретного поля всех записей выборки. Например:
```PHP
$result = $this->qb
->table(...)
->select(['field1', 'field2', ...])
->orderBy(...)
->limit(10)
->column('field2');

/* 
 * $result будет содержать массив из 10 элементов, содержащий значения полей field2 всех записей.
 * 
 * arr:10[
 *      row1.field2,
 *      row2.field2,
 *      row3.field2,
 *      ...
 *      row10.field2
 * ]
 */
```

Имя поля, из значений которого должен формироваться массив, можно указать параметром `$fieldName`. Если параметр не задан, будет использоваться первое поле, заданное методом `select()`.

```PHP
column([string $columnName = null]) : ?array
```

### Count
Возвращает общее кол-во записей в результате запроса. Формирует запрос вида `SELECT COUNT(*) ...`.
```PHP
count(): ?int
```

### Exists
Возвращает булево значение указывающее на то, существует ли в базе запись, удовлетворяющая условиям запроса. 
Структура запроса аналогична select запросам. 
```PHP
exists(): bool
```


## Добавление данных

### Insert
Выполняет запрос `INSERT` и возвращает идентификатор новой записи если в таблице, в которую выполнялась вставка, есть поле со свойством `IDENTITY`. Если такого поля нет, возвращает пустую строку. Принимает на вход ассоциативный массив вида: `[Название поля => Значение]`
```PHP
insert(array $insertData = null)
```

## Обновление данных

### Update
Выполняет запрос `UPDATE`. В качестве первого аргумента может принимать ассоциативный массив вида: `[Название поля => Значение]`

Если условия запроса(where) не были заданы перед вызовом `update()`, метод выбросит исключение. Это сделано, для того чтобы предотвратить случайное изменение всех записей таблицы. Чтобы явно разрешить такое поведение, необходимо вторым аргументом `$ignoreWhere` передать true, тогда исключение выбрасываться не будет и будут обновлены все записи в таблице.

```PHP
update(array $updateData = null, bool $ignoreWhere = false) : void
```

## Удаление данных

### Delete
Перед вызовом метода `delete()` обязательно должны быть указаны условия запроса, иначе метод выбросит исключение. Удаление всех записей в таблице запрещено.
```PHP
delete() : void
```

## Получение чистого sql запроса

### Select SQL
Возвращает сформированный SQL для запросов типа SELECT.
```PHP
$this-qb
    ...
    ->selectSql();
```

## Транзакции
Метод `transaction()` принимает в качестве аргумента анонимную функцию(называемую телом транзакции) в которую передаёт экземпляр QueryBuilder. Внутри тела транзакции может быть сформировано любое кол-во запросов и если хоть один из них выбросит исключение, не будет выполнен ни один.

Для принудительного подтверждения или отмены транзакции, внутри тела транзакции могут быть использованы следующие методы: `$qb->pdo->commit()` и `$qb->pdo->rollback()`.

Пример транзакции:

```PHP
$this-qb
->transaction(function($qb) {
    $qb->table(...)->insert(...);
    
    $qb->table(...)->where(...)->update(...);
    
    $qb->table(...)->where(...)->delete();
});
```

##Прочие методы

### Reset
Сбрасывает состояние объекта до значений по-умолчанию. Вызывается методом `table()`, так что отдельно вызывать нет необходимости, но можно и принудительно сбросить состояние.
```PHP
reset() : void
```
