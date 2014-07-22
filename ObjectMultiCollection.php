<?php
namespace Colibri\Database;

use Colibri\Base\Error;
use Colibri\Database\ObjectCollection;

/**
 * ObjectMultiCollection
 *
 */
class ObjectMultiCollection extends ObjectCollection //implements IObjectMultiCollection
{
	protected	$fkTableName='fkTableName_not_set';
	public		function	__get($propertyName)
	{
		switch	($propertyName)
		{
			case 'parentID':			return $this->FKValue[0];
			case 'addToDbQuery':		return 'INSERT INTO `'.$this->fkTableName.'` SET '  .$this->FKName[0].'='.$this->FKValue[0].', '   .$this->FKName[1].'='.$this->FKValue[1];
			case 'delFromDbQuery':		return 'DELETE FROM `'.$this->fkTableName.'` WHERE '.$this->FKName[0].'='.$this->FKValue[0].' AND '.$this->FKName[1].'='.$this->FKValue[1];
			case 'selFromDbAllQuery':	$strQuery=$this->FKValue[0] !== null ?
											'SELECT o.* FROM `'.$this->tableName.'` o inner join `'.$this->fkTableName.'` f  on o.id=f.'.$this->FKName[1].' WHERE f.'.$this->FKName[0].'='.$this->FKValue[0] :
											'SELECT o.* FROM `'.$this->tableName.'` o WHERE 1';
										$strQuery=$this->rebuildQueryForCustomLoad($strQuery);
										if ($strQuery===false)
											Error::__raiseError(401,$propertyName,__METHOD__,__LINE__);
										return $strQuery;
			case 'delFromDbAllQuery':	return 'DELETE FROM `'.$this->fkTableName.'` WHERE '.$this->FKName[0].'='.$this->FKValue[0];
			default:					parent::__get($propertyName);
		}
	}

	// with DataBase
	///////////////////////////////////////////////////////////////////////////
	protected	function	addToDb($id)
	{
		$this->FKValue[1]=$id;
		return $this->doQuery($this->addToDbQuery);
	}
	protected	function	delFromDb($id)
	{
		$this->FKValue[1]=$id;
		return $this->doQuery($this->delFromDbQuery);
	}
	protected	function	selFromDbAll()
	{
		if (!($this->doQuery($this->selFromDbAllQuery)))
			return false;
		return $this->_db->fetchAllRows();
	}
	protected	function	delFromDbAll()
	{
		return $this->doQuery($this->delFromDbAllQuery);
	}
	///////////////////////////////////////////////////////////////////////////
}
