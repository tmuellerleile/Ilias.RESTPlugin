<?php
require_once "./Services/Database/classes/class.ilAuthContainerMDB2.php";
/*require_once "./Services/Database/classes/class.ilAuthContainerMDB2.php";
require_once "./Modules/File/classes/class.ilObjFile.php";
require_once "./Services/User/classes/class.ilObjUser.php";
*/
class ilDescribrModel
{

    /**
     * Returns a description of an ILIAS object consisting of
     * - Name, Type, Creation Date, Last Update
     * - Owner description
     * - Location within the repository
     *
     * @param $obj_id
     * @return array
     */
    public function describeIliasObject($obj_id)
    {
        $a_objdata = $this->getObjectData($obj_id);
        $owner_id = $a_objdata['owner'];

        $a_usrdata = $this->getUserData($owner_id);
        foreach ($a_usrdata as $key => $value)
        {
            $a_objdata['ext_owner'][$key] = $value;
        }

        $a_refids = ilRestLib::objid_to_refids($obj_id);
        foreach ($a_refids as $ref_id)
        {
            $a_objdata['ext_refids'][] = $ref_id;
        }

        $first_ref_id = $a_refids[0];
        $str_hinfo=$this->getHierarchyInfo($first_ref_id);
        $h=$this->reverse_hierarch_str($str_hinfo);
        $a_objdata['location']['ref_id'] = $first_ref_id;
        //$a_objdata['location']['hstr'] = $str_hinfo;
        foreach ($h as $rep_element)
        {
            $a_objdata['location']['path'][] = $rep_element;
        }

        return $a_objdata;
    }

    public function describeFile($id)
    {
        $model = new ilFileModel();

        $fileObj = $model->getFileObj($id);
        $result = array();
        $result['ext'] = $fileObj->getFileExtension();
        $result['name'] = $fileObj->getFileName();
        $result['size'] = $fileObj->getFileSize();
        $result['type'] = $fileObj->getFileType();
        $result['dir'] = $fileObj->getDirectory();
        $result['version'] = $fileObj->getVersion();
        $result['realpath'] = $fileObj->getFile();
        return $result;
    }


    /**
     * Provides object properties as stored in table object_data.
     *
     * @param $obj_id
     * @return mixed
     */
    protected  function getObjectData($obj_id)
    {
        global $ilDB;
        $query = "SELECT * FROM object_data WHERE object_data.obj_id=".$obj_id;
        $set = $ilDB->query($query);
        $row = $ilDB->fetchAssoc($set);
        return $row;
        /*while($row = $ilDB->fetchAssoc($set))
        {
            $res[] = $row;
        }
        return $res;
        */
    }

    /**
     * Provides user properties given the user_id.
     *
     * @param $owner_id
     * @return mixed
     */
    protected function getUserData($owner_id)
    {
        global $ilDB;
        $query="SELECT usr_id, login, firstname, lastname, gender, email, last_login, last_update, create_date FROM usr_data WHERE usr_id=".$owner_id;
        $set = $ilDB->query($query);
        $row = $ilDB->fetchAssoc($set);
        return $row;
    }

    /**
     * Helper function to query the repository.
     * see also getHierarchyInfo()
     * @param $rid
     * @return mixed
     */
    private function get_next_parent($rid)
    {
        global $ilDB;
        $query="SELECT parent FROM tree WHERE child=".$rid;
        $set = $ilDB->query($query);
        $row = $ilDB->fetchAssoc($set);
        return $row['parent'];
    }

    /**
     * Another helper function for getHierarchyInfo()
     * @param $hierarch_str_fine_to_coarse
     * @return array
     */
    function reverse_hierarch_str($hierarch_str_fine_to_coarse) {
        $arr = array();
        $arr = explode('<', $hierarch_str_fine_to_coarse);
        $arr = array_reverse($arr);
        return $arr;
    }

    /**
     * Determines the location of the repository object within the repository.
     * Note: only the location (path) of the reference is determined (the underlying
     * object might also be referenced at other locations (with other ref_ids).
     *
     * @param $ref_id
     * @return string
     */
    protected function getHierarchyInfo($ref_id)
    {
        global $ilDB;
        $a_ref_ids=array();
        $parent=$ref_id;
        $a_ref_ids[]=$ref_id;
        while($parent>1){
            $parent = $this->get_next_parent($parent);
            if ($parent > 1){
                $a_ref_ids[]=(int)$parent;
            }
        }
        // Ref_id nach Obj_Id Konversion und Title - Ermittlung
        $hierarch_str="";
        $levels=count($a_ref_ids);
        for ($i=0;$i<$levels;$i++){
            $r_id=$a_ref_ids[$i];
            $sql="SELECT object_data.title, object_data.type FROM object_reference LEFT JOIN object_data ON object_data.obj_id=object_reference.obj_id WHERE object_reference.ref_id=".$r_id;
            $set = $ilDB->query($sql);
            $row = $ilDB->fetchAssoc($set);
            $title=$row['title'];
            $type=$row['type'];
            $hierarch_str.="[\"".$title."\" (".$type.")]";
            if ($i<$levels-1){
                $hierarch_str.="<";
            }
        }
        return $hierarch_str;
    }

}