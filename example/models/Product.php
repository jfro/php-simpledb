<?php

class Product extends SimpleDb_Item
{
	static $table = 'products';
	
	protected function _user()
	{
		return $this->_db->users->id($this->user_id);
	}
}
