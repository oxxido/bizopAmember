<?php

class Am_Navigation_Page_Uri extends Zend_Navigation_Page_Uri
{
    function setResource($resource = null)
    {
        $this->_resource = $resource;
    }
}