<?php

namespace Ivalex\Models;

use Ivalex\Services\Db;

use Ivalex\Views\View;

/**
 * base class for table objects with convertion dynamic properties names
 */
abstract class ActiveRecordEntity
{
    protected $id;

    public function __set($property, $value)
    {
        $propertyName = self::underscoreToCamelCase($property);
        $this->$propertyName = $value;
    }

    /**
     * 
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * contract
     * @return string tableName
     */

    abstract protected static function getTableName(): string;

    /**
     * @param bool $byFieldName if true, then returns an array indexed by fieldnames, otherwise indexed in schema order
     * @return array of indexed fields
     */
    public static function getFields(bool $byFieldName = false): array
    {
        $fields = array();
        $sql = 'DESCRIBE `' . static::getTableName() . '`;';

        $db = Db::getInstance();
        $result =  $db->query($sql);
        foreach ($result as $key => $fieldObject) {
            if ($byFieldName) {
                $fields[$fieldObject->Field] = $key;
            } else {
                $fields[$key] = $fieldObject->Field;
            }
        }

        return $fields;
    }

    public static function getRecordCount(): int
    {
        $sql = 'SELECT COUNT(`id`) AS `cRecords` FROM `' . static::getTableName() . '`;';

        $db = Db::getInstance();
        $result = $db->query($sql);

        return $result[0]->cRecords;
    }

    /**
     * 
     */
    public static function getAllRecords(): array
    {
        $sql = 'SELECT * FROM `' . static::getTableName() . '`;';

        $db = Db::getInstance();
        return $db->query($sql, [], static::class);
    }

    /**
     * can be used for multiple records if 'id' is not unique
     */
    public static function getById(int $id): ?self
    {
        $sql = 'SELECT * FROM `' . static::getTableName() . '` WHERE id=:id;';
        $params = [':id' => $id];

        $db = Db::getInstance();
        $recordById = $db->query($sql, $params, static::class);
        return $recordById ? $recordById[0] : null;
    }

    /**
     * SELECT *
     *   FROM _table_name_
     *   ORDER BY $orderColumn $descOrder[ASC, DESC]
     *   LIMIT $offset, $countFetch
     */
    public static function getRowsGroup(int $offset, int $countFetch, string $orderColumn = 'id', bool $descOrder = false): array
    {
        $sql = 'SELECT * FROM `' . static::getTableName() . '` ' .
            'ORDER BY `' . $orderColumn . '` ' . ($descOrder ? 'DESC ' : 'ASC ') .
            'LIMIT ' . $offset . ', ' . $countFetch . ';';

        $db = Db::getInstance();
        return $db->query($sql, [], static::class);
    }

    public static function findOneByColumn(string $columnName, $value): ?self
    {
        $sql = 'SELECT * FROM `' . static::getTableName() . '` WHERE `' . $columnName . '` = :value LIMIT 1;';
        $params = [':value' => $value];

        $db = Db::getInstance();
        $result = $db->query($sql, $params, static::class);
        if ($result === []) {
            return null;
        }
        return $result[0];
    }

    public function save()
    {
        $tableParams = $this->propertiesToTableParams();
        if ($this->id !== null) {
            $this->update($tableParams);
        } else {
            $this->insert($tableParams);
        }
    }

    private function update(array $tableParams): void
    {

        $sql = 'UPDATE `' . static::getTableName() . '`' .
            ' SET ' . implode(', ', $tableParams['sqlColumnsParams']) .
            ' WHERE id=:id';

        $db = Db::getInstance();
        $db->query($sql, $tableParams['paramsValues']);

        // TODO edited by admin

    }

    private function insert(array $tableParams): void
    {
        $sql = 'INSERT INTO `' . static::getTableName() . '` ' .
            '(' . implode(', ', $tableParams['columns']) . ') ' .
            'VALUES (' . implode(', ', $tableParams['params']) . ')';

        $db = Db::getInstance();
        $db->query($sql, $tableParams['paramsValues']);
        $this->id = $db->getLastInsertId();
    }

    public function delete(): void
    {
        $sql = 'DELETE FROM `' . static::getTableName() . '` WHERE id = :id';
        $params = [':id' => $this->id];

        $db = Db::getInstance();
        $db->query($sql, $params);
        $this->id = null;
    }

    /**
     * 
     */
    private function propertiesToTableParams(): array
    {

        $reflector = new \ReflectionObject($this);
        $properties = $reflector->getProperties();
        $tableParams = [
            'columns' => [],
            'params' => [],
            'sqlColumnsParams' => [],
            'paramsValues' => [],
        ];

        foreach ($properties as $property) {
            $propertyName = $property->getName();
            $columnName = self::camelCaseToUnderscore($propertyName); // lowercase
            $paramName = ':' . $columnName;
            $value = $this->$propertyName;
            $tableParams['columns'][] = '`' . $columnName . '`';
            $tableParams['params'][] = $paramName;
            $tableParams['sqlColumnsParams'][] = $columnName . '=' . $paramName;
            $tableParams['paramsValues'][$paramName] = $value;
        }

        return $tableParams;
    }

    private static function underscoreToCamelCase(string $underscoredString): string
    {
        return lcfirst(str_replace('_', '', ucwords($underscoredString, '_')));
    }

    private static function camelCaseToUnderscore(string $camelCaseString): string
    {
        return strtolower(preg_replace('~(?<!^)[A-Z]~', '_$0', $camelCaseString));
    }

    /**
     * For use in the SQL construction ...WHERE <$field> IN (<$values>)...
     * Convets the array $values to SQL blocks '[OR] (<$field> < =|like > :param<1..N>)'
     * and array of params [':param<1..N>'=><$values>]
     * @param string $field
     * @param array $values
     * @param string $operator comparison operator such as '=' or 'like'
     * @return array ['sql' => (string) SQL_query_blocks, 'params' => (array) [SQL_params_values]]
     */
    protected static function arrayPreparedStatements(string $field, array $values, string $operator = '='): array
    {
        $params = array();
        if (empty($values)) {
            $sql = '`' . $field . '` IN (\'\')';
        } else {
            $sqlBlocks = array();
            foreach ($values as $key => $value) {
                $sqlBlocks[] = '(`' . $field . '`' . $operator . ':param' . $key . ')';
                $params[':param' . $key] = $value;
            }
            $sql = implode(' OR ', $sqlBlocks);
        }

        return ['sql' => $sql, 'params' => $params];
    }
}
