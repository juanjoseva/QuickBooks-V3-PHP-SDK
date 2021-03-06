<?php

namespace QuickBooksOnline\API\Facades;

use QuickBooksOnline\API\Data\IPPReferenceType;
use QuickBooksOnline\API\Data\IPPid;
use QuickBooksOnline\API\Data\IPPBillableStatusEnum;
use QuickBooksOnline\API\Data\IPPTaxApplicableOnEnum;
use QuickBooksOnline\API\Data\IPPPostingTypeEnum;
use QuickBooksOnline\API\Data\IPPLinkedTxn;
use QuickBooksOnline\API\Data\IPPLine;
use QuickBooksOnline\API\Data\IPPMarkupInfo;
use QuickBooksOnline\API\Data\IPPLineDetailTypeEnum;
use QuickBooksOnline\API\Facades\Common\LineDetailFacade;

use QuickBooksOnline\API\Core\CoreConstants;

/**
 * Helper class for deserialize common Complicate Object Type to Object
 * @author Hao
 */
class FacadeHelper{
   /**
    * A helper reflection class to assign object to Target Object
    */
   public static function reflectArrayToObject($classNameOrKeyName, $data, $throwException = TRUE) {
     if(!isset($classNameOrKeyName)){ throw new \Exception("The Class Name or Key Name cannot be NULL when generating Objects.");}
     if(!isset($data) || empty($data)){ throw new \Exception("The passed data cannot be NULL.");}
     //Get reflection class of FacadeHelper
     $trimedData = FacadeHelper::trimSpacesForArrayKeys($data);
     //Any key in the Ignored List will not be processed
     $IgnoredList = FacadeClassMapper::IgnoredComplexTypeNameEntity();
     //Intuit does not name every Key with its corresponding class as IPP . $key; for some classes, the IPP . $key was not a class name. Those
     //type will located at the Mapper as an array
     $ObjectMap = FacadeClassMapper::classMethodToList();

     //If the key is in complexList
     if(FacadeHelper::isKeyInComplexList($classNameOrKeyName)){
         $methodFound = FacadeHelper::isKeyInComplexList($classNameOrKeyName);
         $createdObj = FacadeHelper::getComplexListObject($methodFound, $classNameOrKeyName, $data);
         return $createdObj;
     } else if(FacadeHelper::isKeyEnumType($classNameOrKeyName)){
         $enumTypeClassName = FacadeHelper::isKeyEnumType($classNameOrKeyName);
         $createdObj = FacadeHelper::getEnumType($enumTypeClassName, $data);
         return $createdObj;
     }
     else{
         //The key can be constructed with an IPPObject
         $clazz = FacadeHelper::decorateKeyWithNameSpaceAndPrefix($classNameOrKeyName);
         if(class_exists($clazz)){
            $currentObj = new $clazz();
            foreach($data as $key => $val){
                if(is_array($val)){
                    if (FacadeHelper::isAssociateArray($val)){
                       //Key value pair. The value can be another array
                       //For example, SalesItemLineDetail as $key and
                       //          {
                       //  "ItemRef": {
                       //      "value": "14",
                       //      "name": "Sod"
                       //  },
                       //  "Qty": 0,
                       //  "TaxCodeRef": {
                       //      "value": "TAX"
                       //  }
                       // }as $val
                       $obj = FacadeHelper::reflectArrayToObject($key, $val, $throwException);
                       FacadeHelper::assignValue($currentObj, $key, $obj);
                    }
                    else if(FacadeHelper::isRecurrsiveArray($val)){
                        //The array is a recursive array. It can be an Line or LinkedTxn
                        //Example:
                        // Line": [{ ....}, {...}]
                        //For each element in the array, it is a line
                        $list = array();
                        foreach ($val as $index => $element) {
                            $obj = FacadeHelper::reflectArrayToObject($key, $element, $throwException);
                            array_push($list, $obj);
                        }
                        FacadeHelper::assignValue($currentObj, $key, $list);
                    }else{
                        throw new \Exception("Internal Error. The Passed Array is neither associated array or recursive array.");
                    }

                }else{
                    //Even the value is a key, the key can be an Enum type or a wrapper
                    if(FacadeHelper::isKeyInComplexList($key)){
                        $methodFound = FacadeHelper::isKeyInComplexList($key);
                        $createdObj = FacadeHelper::getComplexListObject($methodFound, $key, $val);
                        FacadeHelper::assignValue($currentObj, $key, $createdObj);
                    }
                    //If it is enum type
                    else if(FacadeHelper::isKeyEnumType($key)){
                        $enumTypeClassName = FacadeHelper::isKeyEnumType($key);
                        $createdObj = FacadeHelper::getEnumType($enumTypeClassName, $val);
                        FacadeHelper::assignValue($currentObj, $key, $createdObj);
                    }
                    //It is a simple type
                    else
                    {
                      FacadeHelper::assignValue($currentObj, $key, $val);
                    }
                }
            }
            return $currentObj;
         }else{
            //The key can't be construct with An App Object
            if($throwException)
            {
              throw new \Exception("The name value:{" . $classNameOrKeyName . "} can't be used to construct an Intuit Entity Object. Please check the name and try again.");
            }else{
              return NULL;
            }
         }
     }
   }

   /**
    * A helper to check if a key is a complex type of an Object
    * @param  $key, $complexList = NULL
    * @return true
    *             If the key is found in the complex type
    *         false
    *             If the key is not found in the complex type
    */
   private static function isKeyInComplexList($key, $complexList = NULL){
     if(isset($complexList)) {$ObjectMap = $complexList;}
     else {$ObjectMap = FacadeClassMapper::classMethodToList();}

     foreach($ObjectMap as $objectMethodName => $entityType)
     {
         if(in_array($key, $entityType)){
            return $objectMethodName;
         }
     }
     return false;
   }

   /**
    * Set the Target Object with the Val converted in Object
    */
   private static function setKeyInComplexList($objectMethodName, $targetObject, $key, $val){
     $reflectionClassOfTargetObject = new \ReflectionClass($targetObject);
     $setObject = FacadeHelper::getComplexListObject($objectMethodName, $key, $val);
     $property = $reflectionClassOfTargetObject->getProperty($key);
     if($property instanceof \ReflectionProperty){
         $property->setValue($targetObject,$setObject);
         return true;
     }else{
       throw new \Exception("No Reflection Property Found.");
     }

   }

   /**
    * Set the Target Object with the Val converted in Object
    */
   private static function getComplexListObject($objectMethodName, $key, $val){
     //call helper method with method name as $objectmethodName
     $correspondingClassMethodOfFacadeHelper = FacadeHelper::getClassMethod(FacadeConstants::FACADE_HELPER_CLASS_NAME, $objectMethodName);
     if(!isset($correspondingClassMethodOfFacadeHelper)) throw new \Excetpion("The given Method " . $objectMethodName . " can't find at FacadeHelper.php class");
     $setObject = $correspondingClassMethodOfFacadeHelper->invoke(null, $val, $key);
     return $setObject;
   }

   public static function isKeyEnumType($key){
      $enumSupportArray = FacadeClassMapper::EnumTypeMatch();
      foreach($enumSupportArray as $k => $v){
          if(strcmp($key, $k) == 0){
             return FacadeHelper::decorateKeyWithNameSpaceAndPrefix($v);
          }
      }
      return false;
   }

   public static function getEnumType($clazz, $val){
      if(!isset($val)) throw new \Exception("Passed param for Enum can't be null.");
      if(class_exists($clazz)){
          $enumObj = new $clazz();
          //If $val is string
          if(is_array($val) && !empty($val)){
             $firstElementValue = reset($val);
          }else if(is_string($val) || is_numeric($val)){
             $firstElementValue = $val;
          }else{
             throw new \Exception("Internal Error. The Type of val:" . get_class($val) . " is not handled.");
          }
          $enumObj->value = $firstElementValue;
          return $enumObj;
      }else{
        throw new \Exception("The Enum Type is not found");
      }
   }

   /**
   * Find the Method by given parameter on Facadehelper.php Class
   * @param The class name
   * @return The method if found. If not found, return null.
   */
   public static function getClassMethod($className, $methodName){
          try
          {
              $helperRefelctionMethod = new \ReflectionMethod($className, $methodName);
              return $helperRefelctionMethod;
          } catch(\Exception $e){
               return null;
          }
   }


   //----------------------------------------- Complex Type Methods -------------------------------------------
   /**
    * Construct an IPPReferenceType based on passed Array or String.
    * If it is passed as an array, handle it.
    * If it is passed an a String. Construct an array and put the String on the value
    * @param $data
    *       It can either be an array or a String
    */
   public static function getIPPReferenceTypeBasedOnArray($data){
      $trimedDataArray = FacadeHelper::trimSpacesForArrayKeys($data);
      //THe ReferenceDataType should only contain at most Two elements
      if(is_array($trimedDataArray)){
        if(sizeof($trimedDataArray) >= 3){
          throw new \Exception("Trying to construct IPPReferenceType based on Array. The array should contain at most two fields. name and value");
        }

        $IPPReferenceType = new IPPReferenceType();
        if(isset($trimedDataArray['value']) || isset($trimedDataArray['Value']) ) {
            $val = isset($trimedDataArray['value']) ? $trimedDataArray['value'] : $trimedDataArray['Value'];
            $IPPReferenceType->value = $val;
        }else{
            throw new \Exception("Passed array has no key for 'Value' when contructing an ReferenceType");
        }

        if(isset($trimedDataArray['name']) || isset($trimedDataArray['Name']) ){
           $nam = isset($trimedDataArray['name']) ? $trimedDataArray['name'] : $trimedDataArray['Name'];
           $IPPReferenceType->name = $nam;
         }
        return $IPPReferenceType;
      }else if(is_numeric($trimedDataArray) || is_string($trimedDataArray)){
        $IPPReferenceType = new IPPReferenceType();
        $IPPReferenceType->value = $trimedDataArray;
        return $IPPReferenceType;
      }else{
        throw new \Exception("Can't convert Passed Parameter to IPPReferenceType.");
      }
  }

  /**
   * If passed params is array, the first element of Array is used in IPPid.
   * If passed params is not an array, the the value is used for Ippid.
   * @param $data
   *       It can either be an array or a numeric representation
   */
  public static function getIPPId($data){
    //Convert an IPPId based on the Data
    if(!isset($data)) throw new \Exception("Passed param for IPPid can't be null");
    if(is_array($data)){
       $firstElementValue = reset($data);
    }else if(is_numeric($data)){
       $firstElementValue = $data;
    }else{
      throw new \Exception("Passed param for Ippid has either be an array or numeric value.");
    }

    $_id = new IPPid();
    $_id->value = $firstElementValue;
    return $_id;
  }

  /**
   * Override the content from Object B to Object A
   * Don't use array_merge here. As the NUll Value will be overriden as well
   */
  public static function mergeObj($objA, $objB){
      if(get_class($objA) != get_class($objB)) throw new \Excetpion("Can't assign object value to a different type.");
      $property_fields = get_object_vars($objA);
      foreach ($property_fields as $propertyName => $val){
          $BsValue = $objB->$propertyName;
          if(isset($BsValue) && !empty($BsValue)){
               $objA->$propertyName = $BsValue;
          }
      }
      return $objA;
  }




  //----------------------------------- Common Helper Methods ----------

   public static function trimSpacesForArrayKeys($data){
       if(!isset($data) || empty($data)) return $data;
       if(is_array($data))
       {
           $trimedKeys = array_map('trim', array_keys($data));
           $trimedResult = array_combine($trimedKeys, $data);
           return $trimedResult;
       }else{
           return trim($data);
       }
   }


   public static function isRecurrsiveArray(array $array){
      foreach($array as $key => $val){
          if(!is_array($val)){
                return false;
          }
      }
      return true;
   }

   /**
    * Test if an array is an associate array
    *
    * @param array $arr
    * @return true if $arr is an associative array
    */
   public static function isAssociateArray(array $arr)
   {
       if(!empty($arr)){
           foreach ($arr as $k => $v) {
               if (is_int($k)) {
                   return false;
               }
           }
           return true;
       }
       return false;
   }

   private static function decorateKeyWithNameSpaceAndPrefix($key){
      $list = FacadeClassMapper::OtherAntiPatternNameEntity();
      foreach($list as $k => $v){
          if(strcmp($k, $key) == 0){
              $key = $v;
              break;
          }
      }
      return CoreConstants::NAMEPSACE_DATA_PREFIX . CoreConstants::PHP_CLASS_PREFIX . $key;
   }

   /**
    *
    * Given the class name, find the field for the key.
    *
    * @param $object
    *      The class object that we are going to call
    * @param $key
    *      The name of the field
    * @param $value
    *      The value to be assigned for the field
    */
   private static function assignValue($targetObject, $key, $value){
     //Reflection Class
     $reflectionClassOfTargetObject = new \ReflectionClass($targetObject);
     $property = $reflectionClassOfTargetObject->getProperty($key);
     if($property instanceof \ReflectionProperty){
        $property->setValue($targetObject,$value);
     }else{
       throw new \Exception("No Reflection Property Found.");
     }
   }

}
