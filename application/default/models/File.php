<?php
/**
 * Class represents records from table files
 * "path" field may contain numeric id - from the uploads table
 * {autogenerated}
 * @property int $file_id 
 * @property string $title 
 * @property string $desc 
 * @property string $path 
 * @property string $mime 
 * @property int $size 
 * @property string $display_type 
 * @property datetime $dattm 
 * @see Am_Table
 */
class File extends ResourceAbstractFile {
    public function getUrl()
    {
        return REL_ROOT_URL . "/content/f/id/" . $this->file_id. '/';
    }
}

class FileTable extends ResourceAbstractTable 
{
    protected $_key = 'file_id';
    protected $_table = '?_file';
    protected $_recordClass = 'File';

    public function getAccessType()
    {
        return ResourceAccess::FILE;
    }
    public function getAccessTitle()
    {
        return ___('Files');
    }
    public function getPageId()
    {
        return 'files';
    }
    
}
