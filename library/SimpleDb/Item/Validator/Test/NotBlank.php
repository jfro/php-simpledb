<?php

class SimpleDb_Item_Validator_Test_NotBlank extends SimpleDb_Item_Validator_Test {
	public function runTest() {
		if (trim($this->fieldValue) == '') {
			return false;
		}
		
		return true;
	}
}