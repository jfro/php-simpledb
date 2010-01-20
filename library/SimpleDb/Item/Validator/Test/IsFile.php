<?php

class SimpleDb_Item_Validator_Test_IsFile extends SimpleDb_Item_Validator_Test {
	public $defaultOptions = array(
		'validExtensions' => array(),
		'md5FileName' => true,
		'fileName' => null,
		'moveTo' => null,
		'maxDimension' => null, // For images only.
		'resize' => false, // For images only.
		'required' => false,
		'createDir' => false // Used in coordination with "moveTo"
	);
	
	public function runTest() {
		extract($this->_options, EXTR_SKIP);
		
		// If any of the other fields are invalid, return to original value and return null.
		if (count($this->validator->getInvalidFields())) {
			$this->returnToOriginalValue();
			return null;
		}

		// Check for programmer error.
		if (!is_array($this->fieldValue)) {
			$this->returnToOriginalValue();
			if ($required) {
				$this->validator->registerInvalid($this->field, $this->formatError($error));
				return false;
			} else {
				$this->returnToOriginalValue();
				return null;
			}
		}

		// Was a file even submitted?		
		if (!$required && $this->fieldValue['error'] == UPLOAD_ERR_NO_FILE) {
			$this->fieldValue = '';
			return null;
		}
		
		// Check for upload error.
		$failedRequireCheck = ($required && $this->fieldValue['error'] != UPLOAD_ERR_OK);
		$failedNonRequireCheck = (!$required && $this->fieldValue['error'] != UPLOAD_ERR_OK && $this->fieldValue['error'] != UPLOAD_ERR_NO_FILE);
		if ($failedRequireCheck || $failedNonRequireCheck) {
			$this->returnToOriginalValue();
			$this->validator->registerInvalid($this->field, $this->formatError('There was an error uploading your file.  Please try again.'));
			return false;
		}
		
		// Check for invalid extension.
		if (!is_array($validExtensions)) {
			$validExtensions = explode(',', str_replace(', ', ',', $validExtensions));
		}
		
		$lastDot = strrpos($this->fieldValue['name'], '.');
		$extension = strtolower(substr($this->fieldValue['name'], $lastDot+1));
		if (count($validExtensions) && !in_array($extension, $validExtensions)) {
			$this->returnToOriginalValue();
			$this->validator->registerInvalid($this->field, $this->formatError('You may only upload images with the following extensions: :validExtensions.'));
			return false;
		}
		
		if ($maxDimension) {
			// It is an image, and we expect to not be over the given max dimension.
			$imageInfo = getimagesize($this->fieldValue['tmp_name']);
			if ($imageInfo[0] > $maxDimension || $imageInfo[1] > $maxDimension) {
				if (!$resize) {
					$this->returnToOriginalValue();
					$this->validator->registerInvalid($this->field, $this->formatError('The image you specify must be no greater than :maxDimension pixels wide or high.'));
					return false;					
				}
				
				require_once 'RSC/RSC.php';
				$img = new Image();
				$img->resize($this->fieldValue['tmp_name'], $this->fieldValue['tmp_name'], $maxDimension, $maxDimension);
			}
		}
		
		if ($md5FileName) {
			// They want the field value updated to be the md5.
			$fileName = md5_file($this->fieldValue['tmp_name']).'.'.$extension;
		} elseif ($fileName === null) {
			$fileName = $this->_filterFileName($this->fieldValue['name']);
		}
		
		if ($moveTo != '') {
			// They want to move it somewhere.
			if (!is_dir($moveTo) || !is_writeable($moveTo)) {
				if ($createDir) {
					mkdir($moveTo);
					chmod($moveTo, 0777);
				} else {
					throw new Exception('Could not move file '.$this->fieldValue['tmp_name'].' to '.$moveTo.' because this path is either not a directory or is not writable.');
				}
			}

			move_uploaded_file($this->fieldValue['tmp_name'], rtrim($moveTo, '/').'/'.$fileName);
		}
		
		$this->fieldValue = $fileName;
		
		return true;
	}
	
	public function returnToOriginalValue() {
		$this->_item->{$this->field} = $this->_item->originalValueForField($this->field);
	}
	
	/**
	 * Filter File Name
	 *
	 * Method description
	 *
	 * @param string $fileName The original file name.
	 * @return string The sanitized file name.
	 */
	protected function _filterFileName($fileName) {
		$allowedRegEx = 'a-z0-9\-_ \(\)\.';
		
		// Get rid of leading dots.
		$fileName = ltrim($fileName, '.');
		
		// Sanitize "invalid" characters
		$fileName = preg_replace('/[^'.$allowedRegEx.']/i', '_', $fileName);
		
		// Trim off leading underscores.
		$fileName = ltrim('_', $fileName);
		
		// Replace instances of more than one underscore in a row.
		$fileName = preg_replace('/_+/', '_', $fileName);
		
		return $fileName;
	}
}