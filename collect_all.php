<?php
/*
    The MIT License (MIT)
    Copyright (c) 2016 Francesco Pasa

    Permission is hereby granted, free of charge, to any person obtaining a
    copy of this software and associated documentation files (the "Software"),
    to deal in the Software without restriction, including without limitation
    the rights to use, copy, modify, merge, publish, distribute, sublicense,
    and/or sell copies of the Software, and to permit persons to whom the
    Software is furnished to do so, subject to the following conditions:

    The above copyright notice and this permission notice shall be included in
    all copies or substantial portions of the Software.

    THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
    IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
    FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL
    THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR
    OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE,
    ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR
    OTHER DEALINGS IN THE SOFTWARE.
*/

// shortcut
function All($table)
{
    return new Collection($table);
}

/*
 * Assumes:
 *   1) Tables are capitalized
 *   2) Tables have an id column
 *   3) Columns which end with 'Id' are links between the current row and the
 *      row of the table named with the column name (without 'Id') that has the
 *      id cotained in the field. Example: column 'messageId' with walue 3 will
 *      be considered a link to the row with id = 3 of the table 'Message' 
 *   4) Multiple links between Table1 and Table2 are created with a
 *      table Table1Table2 which contains the columns table1Id and table2Id.
 */
class Collection implements Countable, Iterator
{
    private static $tables = [];
    private static $db = null;

    public $table = null;
    private $valid = false;
    private $validNum = false;
    private $num = null;
    private $queryResult = null;
    private $ids = [];
    private $currentRow = null;
    private $iterationCounter = -1;

    private $filters = [];
    private $orderings = [];
    private $limit = null;
    private $offset = null;

    private $setCache = [];

    // safe query
    public static function query($sql, $vars=[])
    {
        //var_dump($sql, '<br>', $vars, '<br>');
        $query = self::$db->prepare($sql);

        if (!$query) {
            var_dump(self::$db->errorInfo());
        }
        
        foreach ($vars as $key => $value) {
            $query->bindValue($key, $value);
        }

        if (!$query->execute()) {
            var_dump($query->errorInfo());
        }

        return $query;
    }
    
    public static function setPDO($pdo)
    {
        self::$db = $pdo;
    }

    public function __construct ($table, $disableError=false)
    {
        $this->table = $table;

        if (!self::$tables) {
            $result = self::query(
                'SELECT name FROM sqlite_master WHERE type=\'table\'');

            while ($row = $result->fetch()) {
                array_push(self::$tables, $row['name']);
            }
        }

        if (!$disableError && !in_array($this->table, self::$tables)) {
            throw new Exception('Table "' . $this->table . '" does not exist.');
        }
        
        return $this;
    }

    public function count()
    {
        if (!$this->validNum) {
            $this->validNum = true;
            list($sql, $vars) = $this->buildQuery('COUNT');
            $this->num = (int) self::query($sql, $vars)->fetchColumn();

            if ($this->limit && $this->limit < $this->num) {
                $this->num = $this->limit;
            }
        }

		return $this->num;
	}
	
	public function isEmtpy()
	{
	    return !$this->count();
	}

    public function rewind()
    {
        $this->iterationCounter = -1;

        if (!$this->valid) {
            $this->valid = true;

            list($sql, $vars) = $this->buildQuery('SELECT');
            $this->queryResult = self::query($sql, $vars);
        }

        $this->next();
	}

    public function next()
    {
        $this->iterationCounter++;
        $this->currentRow = $this->queryResult->fetch();
        $this->ids[$this->iterationCounter] = $this->currentRow['id'];
	}

    public function current()
    {
        return $this;
    }

    public function key()
    {
        return $this->iterationCounter;
    }
    
    public function valid() {
		$valid = $this->currentRow;

        if (!$valid) {
            $this->valid = false;
        }

        return $valid;
    }

    private function buildQuery($type, $updateVars=null)
    {
        $vars = [];
        if ($type == 'SELECT') {
            $sql = 'SELECT * FROM ' . $this->table . ' ';
        } else if ($type == 'COUNT') {
            $sql = 'SELECT COUNT (*) FROM ' . $this->table . ' ';
        } else if ($type == 'UPDATE') {
            $sql = 'UPDATE ' . $this->table . ' SET ';

            $strings = [];
            $j = 0;
            foreach ($updateVars as $key => $var) {
                array_push($strings, $key . '=:fieldVal' . $j);
                $vars[':fieldVal' . $j] = $var;
                $j++;
            }

            $sql .= implode(', ', $strings) . ' ';
        } else if ($type == 'INSERT') {
            $sql = 'INSERT INTO ' . $this->table . ' (';

            $keys = [];
            $j = 0;
            foreach ($updateVars as $key => $var) {
                array_push($keys, $key);
                $vars[':' . $key] = $var;
                $j++;
            }
            
            $addColumn = function ($v)
            {
                return ':' . $v;
            };

            $sql .= implode(', ', $keys) . ') VALUES (';
            $sql .= implode(', ', array_map($addColumn, $keys)) . ');';
            
            return [$sql, $vars];
        } else if ($type == 'DELETE') {
            $sql = 'DELETE FROM ' . $this->table . ' ';
        }

        if ($this->filters) {
            $conditions = [];

            $i = 0;
            foreach ($this->filters as $filter) {
                $field = $filter['field'];
                $operator = $filter['operator'];
                $value = $filter['value'];
                $raw = $filter['raw'];

                if ($filter == 'BETWEEN') {
                    array_push($conditions, $field
                        . ' BETWEEN :filterVal1_' . $i
                        . ' AND :filterVal2_' . $i);
                    $vars[':filterVal1_' . $i] = $value[0];
                    $vars[':filterVal2_' . $i] = $value[1];
                } else {
                    if ($raw) {
                        array_push($conditions, $field . $operator . $value);
                    } else {
                        array_push($conditions,
                            $field . $operator . ':filterVal' . $i);
                        $vars[':filterVal' . $i] = $value;
                    }
                }

                $i++;
            }

            $sql .= 'WHERE ' . implode(' AND ', $conditions) . ' ';
        }
    
        
        if ($type == 'UPDATE' &&
            ($this->limit || $this->offset || $this->orderings))
        {
            if (!$this->filters) {
                $sql .= 'WHERE ';
            } else {
                $sql .= 'AND ';
            }

            $sql .= 'id IN (SELECT id FROM :tableInner ';
            $vars[':tableInner'] = $this->table;
        }

        if ($this->limit) {
            $sql .= 'LIMIT :limit ';
            $vars[':limit'] = $this->limit;
        }
        
        if ($this->offset) {
            $sql .= 'OFFSET :offset ';
            $vars[':offset'] = $this->offset;
        }
        
        if ($this->orderings) {
            $orderings = [];

            $i = 0;
            foreach ($this->orderings as $ordering) {
                list($field, $direction) = $ordering;

                array_push($orderings, $field . ' ' . $direction . ' ');

                $i++;
            }

            $sql .= 'ORDER BY ' . implode(',', $orderings) . ' ';
        }
                        
        if ($type == 'UPDATE' &&
            ($this->limit || $this->offset || $this->orderings))
        {
            $sql .= ') ';
        }

        return [$sql . ';', $vars];
    }
    
    // Get first row if not in loop, or current row if in loop
    private function getRow()
    {
        if (!$this->valid) {
            $this->rewind();
        }
        
        $row = $this->currentRow;

        if (!$row) {
            throw new Exception('Collection is empty');
        }
        
        return $row;
    }
    
    public function createNew($data)
    {
        list($sql, $vars) = $this->buildQuery('INSERT', $data);
        self::query($sql, $vars);
        
        $new = new Collection($this->table);
        return $new->filter('id', '=', self::$db->lastInsertId());
    }
    
    public function link($collection)
    {
        $row = $this->getRow();
        $field = strtolower($collection->table);
        $completeField = $field . 'Id';
        
        // Single link
        if (array_key_exists($field . 'Id', $row)) {
            $collectionRow = $this->filter('id', '=', $row['id']);
            $collectionRow->$completeField = $collection->id;
            $collectionRow->save();
            return;
        // Multiple link
        } else if (in_array(ucfirst($field) . $this->table, self::$tables)) {
            $relationTable = ucfirst($field) . $this->table;
        } else if (in_array($this->table . ucfirst($field), self::$tables)) {
            $relationTable = $this->table . ucfirst($field);
        } else {
            throw new Exception('No relation \'' . $field
                . '\' in \'' . $this->table . '\'');
        }
        
        $thisField = strtolower($this->table) . 'Id';
        
        $relationCollection = new Collection($relationTable);
        $relationCollection->createNew([
            $thisField => $row['id'],
            $completeField => $collection->id
        ]);
    }
    
    public function unlink($collection)
    {
        $row = $this->getRow();
        $field = strtolower($collection->table);
        $completeField = $field . 'Id';
        
        // Single link
        if (array_key_exists($field . 'Id', $row)) {
            $collectionRow = $this->filter('id', '=', $row['id']);
            $collectionRow->$completeField = null;
            $collectionRow->save();
            return;
        // Multiple link
        } else if (in_array(ucfirst($field) . $this->table, self::$tables)) {
            $relationTable = ucfirst($field) . $this->table;
        } else if (in_array($this->table . ucfirst($field), self::$tables)) {
            $relationTable = $this->table . ucfirst($field);
        } else {
            throw new Exception('No relation \'' . $field
                . '\' in \'' . $this->table . '\'');
        }
        
        $thisField = strtolower($this->table) . 'Id';
        
        $relationCollection = new Collection($relationTable);
        $relationCollection
            ->filter($thisField, '=', $row['id'])
            ->filter($completeField, '=', $collection->id)
            ->delete();
    }
    
    public function delete()
    {
        foreach ($this as $row) {
            $collection = new Collection($this->table);
            $collection = $collection->filter('id', '=', $row->id);

            list($sql, $sqlVars) = $collection->buildQuery('DELETE');
            self::query($sql, $sqlVars);
        }
    }

    public function filter($field, $operator, $value, $raw=false)
    {
        $clonedCollection = clone $this;
        
        $clonedCollection->valid = $clonedCollection->validNum = false;

        array_push($clonedCollection->filters, [
            'field' => $field,
            'operator' => $operator,
            'value' => $value,
            'raw' => $raw
        ]);
        
        return $clonedCollection;
    }

    public function order($field, $direction='ASC')
    {
        $clonedCollection = clone $this;
        array_push($clonedCollection->orderings, [$field, $direction]);
        return $clonedCollection;
    }

    public function offset($offset)
    {
        $clonedCollection = clone $this;
        $clonedCollection->valid = $clonedCollection->validNum = false;
        $clonedCollection->offset = $offset;
        return $clonedCollection;
    }

    public function limit($limit)
    {
        $clonedCollection = clone $this;
        $clonedCollection->valid = $clonedCollection->validNum = false;
        $clonedCollection->limit = $limit;
        return $clonedCollection;
    }

    public function __get($field)
    {
        $row = $this->getRow();

        $getMultipleLinks = function ($relationTable, $field, $id) 
        {
            $otherTable = ucfirst($field);
            $collection = new Collection($relationTable
                . ', ' . $otherTable, true);
            return $collection
                ->filter($relationTable . '.'
                    . strtolower($this->table) . 'Id', '=', $id)
                ->filter($relationTable . '.'
                    . $field . 'Id', '=', $otherTable . '.id', true);
        };

        // field
        if (array_key_exists($field, $row)) {
            return $row[$field];
        // Single link
        } else if (array_key_exists($field . 'Id', $row)) {
            $collection = new Collection(ucfirst($field));
            return $collection->filter('id', '=', $row[$field . 'Id']);
        // Multiple link
        } else if (in_array(ucfirst($field) . $this->table, self::$tables)) {
            $relationTable = ucfirst($field) . $this->table;
            return $getMultipleLinks($relationTable, $field, $row['id']);
        } else if (in_array($this->table . ucfirst($field), self::$tables)) {
            $relationTable = $this->table . ucfirst($field);
            return $getMultipleLinks($relationTable, $field, $row['id']);
        } else {
            throw new Exception('No relation \'' . $field
                . '\' in \'' . $this->table . '\'');
        }
    }

    public function __set($field, $value)
    {
        $this->setCache[$this->iterationCounter][$field] = $value;
    }

    public function save()
    {
        if ($this->iterationCounter == -1) {
            list($sql, $vars) = $this->buildQuery('UPDATE',
                $this->setCache[$this->iterationCounter]);
            self::query($sql, $vars);
        } else if ($this->setCache) {
            foreach ($this->setCache as $num => $vars) {
                $this->filter('id', '=', $this->ids[$num]);

                list($sql, $sqlVars) = $this->buildQuery('UPDATE', $vars);
                self::query($sql, $sqlVars);

                array_pop($this->filters);
            }
        }
    }
}


