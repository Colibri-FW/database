<?php
namespace Colibri\Database;

use Colibri\Base\DynamicCollection;
use Colibri\Base\IDynamicCollection;


/**
 * Абстрактный класс ObjectCollection.
 *
 * Класс основан на DynamicCollection, по сему свои элементы подгружает
 * только тогда, когда идёт первое обращение к элементу коллекции.
 */
abstract
class ObjectCollection extends DynamicCollection implements IDynamicCollection//IObjectCollection
{
    protected static $tableName = 'tableName_not_set';
    protected $itemClass = 'itemClass_not_set';
    protected $FKName = ['_id', '_id'];
    protected $FKValue = [null, null];
    protected $_parentID;
    protected $itemFields = [];
    protected $itemFieldTypes = [];

    protected $where = null;
    protected $order_by = null;
    protected $limit = null;

    public $recordsPerPage = 20;
    public $recordsCount = null;
    public $pagesCount = null;

    /**
     *
     * @param mixed $parentID
     */
    public function __construct($parentID = null)
    {
        $this->parentID = $parentID;
    }

    /**
     * IDynamicCollection ::fillItems() implementation
     *
     * @param array $rows
     *
     * @return bool
     */
    public function fillItems(array &$rows = null)
    {
        if ($rows === null)
            return $this->load();

        $this->clearItems();
        foreach ($rows as $row) {
            $item = $this->instantiateItem($row);
            $this->addItem($item);
        }

        return true;
    }

    public function __set($propertyName, $propertyValue)
    {
        switch ($propertyName) {
            case 'parentID':
                return $this->FKValue[0] = $propertyValue;
            default:
                return parent::__set($propertyName, $propertyValue);
        }
    }

    // with Items
    ///////////////////////////////////////////////////////////////////////////
    final
    protected function shiftLeftFromPos($pos)
    {
        $cnt = count($this->_items);
        if ($pos < 1 || $pos >= $cnt) return false;// throw new Exception("pos to shift from must be in range 1..Length-1");
        for ($i = $pos; $i < $cnt; $i++)
            $this->_items[$i - 1] = $this->_items[$i];
    }

    protected function addItem(Object &$obj)
    {
        $this->_items[] = $obj;
    }

    protected function delItem($itemID)
    {
        $pos = $this->indexOf($itemID);
        if ($pos == -1) return false;
        $item = $this->_items[$pos];
        if ($pos != count($this->_items) - 1)
            $this->shiftLeftFromPos($pos + 1);
        array_pop($this->_items);

        return $item;
    }

    protected function clearItems()
    {
        $this->_items = [];
    }

    /**
     * @param array $row
     *
     * @return \Colibri\Database\Object
     */
    protected function instantiateItem(array $row)
    {
        return new $this->itemClass($row);
    }
    ///////////////////////////////////////////////////////////////////////////


    // with DataBase
    ///////////////////////////////////////////////////////////////////////////
    abstract protected function addToDb(Object &$id);

    abstract protected function delFromDb($id);

    //abstract	protected	function	selFromDbAll();
    abstract protected function delFromDbAll();

    protected function selFromDbAll()
    {
        $this->doQuery($this->selFromDbAllQuery);

        return $this->db()->fetchAllRows();
    }

    ///////////////////////////////////////////////////////////////////////////
    protected function doQuery($strQuery)
    {
        $this->db()->query($strQuery);

        return true;
    }

    protected function getFieldsAndTypes()
    {
        if (empty($this->itemFields)) {
            $metadata             = $this->db()->getColumnsMetadata(static::$tableName);
            $this->itemFields     = &$metadata['fields'];
            $this->itemFieldTypes = &$metadata['fieldTypes'];
        }

        return ['fields' => &$this->itemFields, 'types' => &$this->itemFieldTypes];
    }

    protected function buildWhere(array &$clauses, $type)
    {
        $whereParts = [];
        foreach ($clauses as $clause) {
            $name  = $clause[0];
            $value = $clause[1];
            if (is_array($value) && ($name == 'and' || $name == 'or')) {
                $whereParts[] = $this->buildWhere($value, $name);
            } else {
                if (!is_array($name = explode(' ', $name, 2)))
                    $name = [$name];
                if (!isset($name[1]))
                    $name[1] = $value === null ? 'is' : '=';

                $whereParts[] = '`' . $name[0] . '` ' . $name[1] . ' ' . $this->db()->prepareValue($value, $this->itemFieldTypes[$name[0]]);
            }
        }

        return '(' . implode(' ' . $type . ' ', $whereParts) . ')';
    }

    protected function rebuildQueryForCustomLoad($strQuery)
    {
        if ($this->where !== null) {
            if ($this->getFieldsAndTypes() === false)
                return false;

            $where = $this->where;
            if (count($where) !== 1) return false;
            if (isset($where['and'])) {
                $type    = 'and';
                $clauses = $where['and'];
            } else if (isset ($where['or'])) {
                $type    = 'or';
                $clauses = $where['or'];
            } else
                return false;

            $strQuery .= ' AND ' . $this->buildWhere($clauses, $type);

            $this->where = null;
        }

        if ($this->order_by !== null) {
            $strQuery .= ' ORDER BY ';
            $strOrder = '';
            foreach ($this->order_by as $name => $value)
                $strOrder .= ', `' . $name . '` ' . $value;
            $strQuery .= substr($strOrder, 2);
        }

        if ($this->limit !== null) {
            $strQuery = str_ireplace('SELECT ', 'SELECT SQL_CALC_FOUND_ROWS ', $strQuery);
            $strQuery .= ' LIMIT ' . implode(',', $this->limit);
            //$this->limit=null; // its sets to <null> in load()
        }

        return $strQuery;
    }
    ///////////////////////////////////////////////////////////////////////////


    ///////////////////////////////////////////////////////////////////////////
    // for where() function additional funcs.
    /*private	function	whereClauses(array $where,$type='and')
    {
        $whereClauses=$this->buildClauses($where,$type);
        //kminaev - merge atrays in where clause
        if (is_array($this->where))
            $this->where=array_merge($this->where,$whereClauses);
        else
            $this->where=$whereClauses;

        return $this;
    }*/
    private function buildClauses(array $where, $type = 'and')
    {
        if (!in_array($type, ['and', 'or']))
            throw new \InvalidArgumentException('where-type must be `and` or `or`');
        $whereClauses = [];
        foreach ($where as $name => $value)
            $whereClauses[] = [$name, $value];

        return [$type => $whereClauses];
    }
    // public user functions
    ///////////////////////////////////////////////////////////////////////////
    /// additional function for custom load() /////////////////////////////////
    /**
     *
     * @param array  $where
     * @param string $type
     *
     * @return ObjectCollection|$this|Object[]
     */
    final
    public function where(array $where, $type = 'and')
    {
        $where = $this->buildClauses($where, $type);
        if ($this->where === null) {
            $this->where = $where;

            return $this;
        }

        if (isset($this->where[$type]))
            $this->where[$type] = array_merge($this->where[$type], $where[$type]);
        else
            $this->where = $type == 'or'
                ? ['and' => array_merge($this->where['and'], [['or', $where['or']]])]
                : ['and' => array_merge($where['and'], [['or', $this->where['or']]])];

        return $this;//->whereClauses($where);
    }
    /*
     *
     * @param array $where
     * @return ObjectCollection|$this|Object[]
     *//*
	final
	public	function	or_where(array $where)
	{
		return $this->whereClauses($where,'OR');
	}*/
    /**
     * @param array $plan
     *
     * @return ObjectCollection|$this|Object[]
     */
    final
    public function wherePlan(array $plan)
    {
        $this->where = $plan;

        return $this;
    }

    /**
     *
     * @param array $order_by array('field1'=>'orientation','field2'=>'orientation'), 'fieldN' - name of field,
     *                        'orientation' - ascendig or descending abbreviation ('asc' or 'desc')
     *
     * @return ObjectCollection|$this|Object[]
     */
    final
    public function order_by(array $order_by)
    {
        $this->order_by = $order_by;

        return $this;
    }

    /**
     *
     * @param int $offset_or_count
     * @param int $count
     *
     * @return ObjectCollection|$this|Object[]
     */
    final
    public function limit($offset_or_count, $count = null)
    {
        if ($count === null) {
            $this->limit['offset'] = 0;
            $this->limit['count']  = (int)$offset_or_count;
        } else {
            $this->limit['offset'] = (int)$offset_or_count;
            $this->limit['count']  = (int)$count;
        }

        return $this;
    }

    /**
     *
     * @param int $pageNumber 0..N
     * @param int $recordsPerPage
     *
     * @return ObjectCollection|$this|Object[]
     */
    final function page($pageNumber, $recordsPerPage = null)
    {
        if ($recordsPerPage !== null)
            $this->recordsPerPage = (int)$recordsPerPage;
        $this->limit['offset'] = ((int)$pageNumber) * $this->recordsPerPage;
        $this->limit['count']  = $this->recordsPerPage;

        return $this;
    }


    ///////////////////////////////////////////////////////////////////////////
    public function add(Object &$obj)
    {
        if ($this->_items === null) if (!$this->fillItems()) return false;
        if (!$this->addToDb($obj)) return false;
        $this->addItem($obj);

        return true;
    }

    public function remove($itemID)
    {
        if ($this->_items === null) if (!$this->fillItems()) return false;
        if (!$this->delFromDb($itemID)) return false;
        if (($item = $this->delItem($itemID)) === false)
            return false;

        return $item;
    }

    public function clear()
    {
        if (!$this->delFromDbAll()) return false;
        $this->clearItems();

        return true;
    }

    public function load($parentID = null)
    {
        if ($parentID !== null) $this->parentID = $parentID;
        if (!is_array($rows = $this->selFromDbAll()))
            return false;

        if ($this->limit !== null) {
            // TODO [alek13]: bring out into Database\MySQL
            $this->doQuery('SELECT FOUND_ROWS()');
            $row                = $this->db()->fetchRow();
            $this->recordsCount = $row[0];
            $this->pagesCount   = ceil($this->recordsCount / $this->recordsPerPage);
            $this->limit        = null;
        }

        if (!$this->fillItems($rows))
            return false;

        return true;
    }

    public function reload()
    {
        return $this->load();
    }

    public function indexOf($itemID)
    {
        $cnt = count($this);
        for ($i = 0; $i < $cnt; $i++)
            if ($this->_items[$i]->id == $itemID)
                return $i;

        return -1;
    }

    public function contains($itemID)
    {
        if ($this->indexOf($itemID) == -1) return false;
        else                                return true;
    }

    /**
     * @param int|string $id
     *
     * @return bool
     */
    public function    &getItemByID($id)
    {
        $count = count($this->_items);
        /** @var Object $itemClass */
        $itemClass = $this->itemClass;
        if ($count > 0) $PKfn = $itemClass::$PKFieldName[0];
        for ($i = 0; $i < $count; $i++)
            if (isset($this->_items[$i]->$PKfn) && $this->_items[$i]->$PKfn == $id)
                return $this->_items[$i];

        return false;
    }

    /**
     * @param string $fieldName which field push to an array
     * @param string $keyField  which field use as keys of array
     *
     * @return array
     */
    public function    &toArrayOf($fieldName, $keyField = null)
    {
        $arr = [];
        foreach ($this as $object) {
            if ($keyField === null) {
                $arr[] = $object->$fieldName;
            } else {
                $arr[$object->$keyField] = $object->$fieldName;
            }
        }

        return $arr;
    }

    public function implode($fieldName, $glue = ', ')
    {
        return implode($glue, $this->toArrayOf($fieldName));
    }

    /**
     * @return IDb
     */
    protected function db()
    {
        /** @var Object $itemClass */
        $itemClass = $this->itemClass;

        return $itemClass::db();
    }
    ///////////////////////////////////////////////////////////////////////////

    /**
     * @param string $fieldName
     * @param string $keyField
     *
     * @return static|ObjectCollection|Object[]|array
     */
    public static function &all($fieldName = null, $keyField = null)
    {
        $collection = new static();
        if ($fieldName !== null)
            return $collection->toArrayOf($fieldName, $keyField);

        return $collection;
    }

    /**
     * @return $this
     * @throws DbException
     */
    public function get()
    {
        if (!$this->load())
            throw new DbException('failed to load collection');

        return $this;
    }
}
