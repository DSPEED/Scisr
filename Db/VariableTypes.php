<?php

/**
 * Stores information about variable types
 *
 * Basically a very rudimentary static model
 */
class Scisr_Db_VariableTypes
{
    /**
     * Do any necessary setup
     *
     * Sets up the DB table we are going to use
     */
    public function init()
    {
        $db = Scisr_Db::getDb();
        $create = <<<EOS
CREATE TABLE IF NOT EXISTS VariableTypes(filename text, scopeopen integer, variable text, type text, variable_pointer integer);
CREATE INDEX IF NOT EXISTS VariableTypes_index_filename ON VariableTypes (filename);
CREATE UNIQUE INDEX IF NOT EXISTS VariableTypes_index_filename_var_ptr ON VariableTypes (filename, variable_pointer);
CREATE INDEX IF NOT EXISTS VariableTypes_index_filename_var_scope ON VariableTypes (filename, scopeopen, variable);
EOS;
        $db->exec($create);
        $create = <<<EOS
CREATE TABLE GlobalVariables(filename text, scopeopen integer, variable text, variable_pointer integer);
CREATE INDEX IF NOT EXISTS GlobalVariables_index_filename ON GlobalVariables (filename);
CREATE UNIQUE INDEX IF NOT EXISTS GlobalVariables_index_filename_var_ptr ON GlobalVariables (filename, variable_pointer);
CREATE INDEX IF NOT EXISTS GlobalVariables_index_filename_var_scope ON GlobalVariables (filename, scopeopen, variable);
EOS;
        $db->exec($create);
    }

    /**
     * Register a variable as holding a certain class type
     * @param string $variable the name of the variable (including the dollar sign)
     * @param string $type the name of the class that this variable holds
     * @param string $filename the file we're in
     * @param array $scopeOpen the stack pointer to the beginning of the current scope
     * @param int $varPtr a pointer to the beginning of the variable
     */
    public function registerVariableType($variable, $type, $filename, $scopeOpen, $varPtr)
    {
        $db = Scisr_Db::getDb();

        // First delete any previous assignments in this scope
        $delete = <<<EOS
DELETE FROM VariableTypes WHERE filename = ? AND variable_pointer = ?
EOS;
        $delSt = $db->prepare($delete);
        $delSt->execute(array($filename, $varPtr));

        // Update any partially typed entries
        $select = <<<EOS
SELECT variable, variable_pointer FROM VariableTypes WHERE variable LIKE ? AND filename = ? AND scopeopen = ?
EOS;
        $querySt = $db->prepare($select);
        $querySt->execute(array($variable . '->_%', $filename, $scopeOpen));
        while (($result = $querySt->fetch()) !== false) {
            $varPtr = $result['variable_pointer'];
            $varName = $result['variable'];
            $newVarName = substr_replace($varName, $type, 0, strlen($variable));
            $update = <<<EOS
UPDATE VariableTypes SET variable = ? WHERE filename = ? AND variable_pointer = ?
EOS;
            $upSt = $db->prepare($update);
            $upSt->execute(array($newVarName, $filename, $varPtr));
        }

        // Now insert this assignment
        $insert = <<<EOS
INSERT INTO VariableTypes (filename, scopeopen, variable, type, variable_pointer) VALUES (?, ?, ?, ?, ?)
EOS;
        $insSt = $db->prepare($insert);
        $insSt->execute(array($filename, $scopeOpen, $variable, $type, $varPtr));

    }

    /**
     * Register a variable as global
     * @param string $variable the name of the variable (including the dollar sign)
     * @param string $filename the file we're in
     * @param array $scopeOpen the stack pointer to the beginning of the current scope
     * @param int $varPtr a pointer to the beginning of the variable
     */
    public function registerGlobalVariable($variable, $filename, $scopeOpen, $varPtr)
    {
        $db = Scisr_Db::getDb();

        // Now insert this assignment
        $insert = <<<EOS
INSERT INTO GlobalVariables (filename, scopeopen, variable, variable_pointer) VALUES (?, ?, ?, ?)
EOS;
        $insSt = $db->prepare($insert);
        $insSt->execute(array($filename, $scopeOpen, $variable, $varPtr));
    }

    /**
     * See if a type has already been registered for this particular place in 
     * the stack.
     * @param string $filename the file we're in
     * @param int $varPtr a pointer to the beginning of the variable
     * @return string|null the type name registered at this location, or null if none
     */
    public function checkVariableDefinition($filename, $varPtr)
    {
        $db = Scisr_Db::getDb();

        $sql = <<<EOS
SELECT type FROM VariableTypes WHERE filename = ? AND variable_pointer = ?
EOS;
        $st = $db->prepare($sql);
        $st->execute(array($filename, $varPtr));
        $result = $st->fetch();
        if ($result === false) {
            return null;
        } else {
            return $result['type'];
        }
    }

    /**
     * Get the type of a variable
     * @param string $variable the name of the variable (including the dollar sign)
     * @param string $filename the file we're in
     * @param array $scopeOpen the stack pointer to the beginning of the current scope
     * @param int $varPtr a pointer to the beginning of the variable
     * @return string|null the class name, or null if we don't know
     */
    public function getVariableType($variable, $filename, $scopeOpen, $varPtr)
    {
        $db = Scisr_Db::getDb();

        $select = <<<EOS
SELECT type FROM VariableTypes WHERE filename = ? AND variable = ? AND scopeopen = ? AND variable_pointer <= ? ORDER BY variable_pointer DESC LIMIT 1
EOS;
        $st = $db->prepare($select);
        $st->execute(array($filename, $variable, $scopeOpen, $varPtr));
        $result = $st->fetch();

        // If nothing was found, we'll settle for a type found after this point in the file
        if ($result === false) {
            $select = <<<EOS
SELECT type FROM VariableTypes WHERE filename = ? AND variable = ? AND scopeopen = ? AND variable_pointer > ? ORDER BY variable_pointer DESC LIMIT 1
EOS;
            $st = $db->prepare($select);
            $st->execute(array($filename, $variable, $scopeOpen, $varPtr));
            $result = $st->fetch();
        }

        return $result['type'];
    }

    /**
     * See if a variable is in the global scope
     * @param string $variable the name of the variable (including the dollar sign)
     * @param string $filename the file we're in
     * @param array $scopeOpen the stack pointer to the beginning of the current scope
     * @param int $varPtr a pointer to the beginning of the variable
     * @return boolean true if it is global
     */
    public function isGlobalVariable($variable, $filename, $scopeOpen, $varPtr)
    {
        $db = Scisr_Db::getDb();

        $select = <<<EOS
SELECT COUNT(*) FROM GlobalVariables WHERE filename = ? AND variable = ? AND scopeopen = ? LIMIT 1
EOS;
        $st = $db->prepare($select);
        $st->execute(array($filename, $variable, $scopeOpen));
        $result = $st->fetch();
        return $result['COUNT(*)'] > 0;
    }

}
