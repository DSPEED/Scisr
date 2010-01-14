<?php

// The scope in which we store qualified class types
define('SCISR_SCOPE_CLASS', 0);
// The scope in which we store global variable types
define('SCISR_SCOPE_GLOBAL', 0);

/**
 * An abstract operation class that helps you deal with variable types
 *
 * This should sit completely between any Scisr Operations and the
 * Scisr_VariableTypes storage class, since that may change formats,
 * and this has domain knowledge about CodeSniffer.
 */
abstract class Scisr_Operations_AbstractVariableTypeOperation implements PHP_CodeSniffer_Sniff
{

    /**
     * Get the type of a variable
     * @param PHP_CodeSniffer_File $phpcsFile The file the variable is in
     * @param int $varPtr The position in the stack in which our variable has scope
     * @param string $varName the name of the variable. If not provided, will
     * be determined from $varPtr.
     * @return string|null the class name, or null if we don't know
     */
    protected function getVariableType($varPtr, $phpcsFile, $varName=null)
    {
        $tokens = $phpcsFile->getTokens();
        $varInfo = $tokens[$varPtr];

        if ($varName === null) {
            $varName = $varInfo['content'];
        }

        // Special case: $this inside a class
        if ($varName == '$this'
            && ($classDefPtr = array_search(T_CLASS, $varInfo['conditions'])) !== false
        ) {
            $classPtr = $phpcsFile->findNext(T_STRING, $classDefPtr);
            $type = $tokens[$classPtr]['content'];
            return $type;
        }

        $scopeOpen = $this->getScopeOwner($varPtr, $phpcsFile, $varName);

        return Scisr_VariableTypes::getVariableType($varName, $phpcsFile->getFileName(), $scopeOpen);

    }

    /**
     * Set the type of a variable
     * @param PHP_CodeSniffer_File $phpcsFile The file the variable is in
     * @param int $varPtr a pointer to the beginning of the variable
     * @param string $type the name of the class that this variable holds
     * @param string $varName the name of the variable. If not provided, will
     * be determined from $varPtr.
     * @param int $scopeOpen a pointer to the element owning the variable's scope.
     * If not provided, will be determined from $varPtr.
     */
    protected function setVariableType($varPtr, $type, $phpcsFile, $varName=null, $scopeOpen=null)
    {
        $tokens = $phpcsFile->getTokens();
        $varInfo = $tokens[$varPtr];

        if ($varName === null) {
            $varName = $varInfo['content'];
        }

        if ($scopeOpen === null) {
            $scopeOpen = $this->getScopeOwner($varPtr, $phpcsFile, $varName);
        }

        // Special case: property or method declaration inside a class
        // Change the variable name to match the way it will be referenced
        if ($scopeOpen !== null && $tokens[$scopeOpen]['code'] == T_CLASS) {
            $classNamePtr = $phpcsFile->findNext(T_STRING, $scopeOpen);
            $className = $tokens[$classNamePtr]['content'];
            // If it's a property, strip off the $ symbol
            if (substr($varName, 0, 1) == '$') {
               $varName = substr($varName, 1);
            }
            $varName = $className . '->' . $varName;
            // Recalculate the owning scope in case it has changed
            $scopeOpen = $this->getScopeOwner($varPtr, $phpcsFile, $varName);
        }

        // If a type has already been set for this variable that is more 
        // specific than this type, we don't overwrite it
        $existing = Scisr_VariableTypes::checkVariableDefinition($phpcsFile->getFileName(), $varPtr);
        if ($existing !== null && $existing != $type) {
            $existingArray = explode('->', $existing);
            $existingSpecificity = count($existingArray) + preg_match('/^\$|^\*/', $existingArray[0]);
            $typeArray = explode('->', $type);
            $typeSpecificity = count($typeArray) + preg_match('/^\$|^\*/', $typeArray[0]);
            if ($typeSpecificity > $existingSpecificity) {
                return;
            }
        }

        Scisr_VariableTypes::registerVariableType($varName, $type, $phpcsFile->getFileName(), $scopeOpen, $varPtr);
    }

    /**
     * Filter the array of scopes we get from CodeSniffer
     *
     * We don't want things like conditionals in our scope list, since for our
     * purposes we're just ignoring those.
     *
     * @param array a list of stack pointers => token types as Codesniffer generates them
     * @return an array of stack pointers we care about
     */
    protected static function filterScopes($scopes)
    {
        $acceptScope = create_function('$type', 'return (in_array($type, array(T_CLASS, T_INTERFACE, T_FUNCTION)));');
        $scopes = array_keys(array_filter($scopes, $acceptScope));
        return $scopes;
    }

    /**
     * Set a variable as global
     * @param int $varPtr The position in the stack in which our variable has scope
     * @param PHP_CodeSniffer_File $phpcsFile The file the variable is in
     */
    protected function setGlobal($varPtr, $phpcsFile)
    {
        $tokens = $phpcsFile->getTokens();
        $varName = $tokens[$varPtr]['content'];
        $scopeOpen = $this->getScopeOwner($varPtr, $phpcsFile, $varName);
        Scisr_VariableTypes::registerGlobalVariable($varName, $phpcsFile->getFileName(), $scopeOpen);
    }

    /**
     * Figure out the relevant scope opener
     * @param PHP_CodeSniffer_File $phpcsFile The file the variable is in
     * @param int $varPtr The position in the stack in which our variable has scope
     * @param string $varName the name of the variable.
     * @return int the stack pointer that opens the scope for this variable
     */
    private function getScopeOwner($varPtr, $phpcsFile, $varName)
    {
        $tokens = $phpcsFile->getTokens();
        $varInfo = $tokens[$varPtr];

        $scopes = self::filterScopes($varInfo['conditions']);

        if ($varName{0} != '$' && $varName{0} != '*') {
            // If we're dealing with a fully qualified variable, put it in the global scope
            $scopeOpen = SCISR_SCOPE_CLASS;
        } else if ($this->isGlobal($varName, $phpcsFile->getFileName(), $scopes)) {
            // If the variable was declared global, use that
            $scopeOpen = SCISR_SCOPE_GLOBAL;
        } else {
            // Get the lowermost scope
            $scopeOpen = $scopes[count($scopes) - 1];
        }
        return $scopeOpen;
    }

    /**
     * See if a variable is in the global scope
     * @param string $name the name of the variable (including the dollar sign)
     * @param string $filename the file we're in
     * @param array $scopes an array of scope opener pointers (not as received from CodeSniffer)
     * @return boolean true if the variable is global
     */
    private function isGlobal($name, $filename, $scopes)
    {

        // If we have no scope, we're global without trying
        if (count($scopes) == 0) {
            return true;
        }
        // Get the lowermost scope
        $scopeOpen = $scopes[count($scopes) - 1];

        return Scisr_VariableTypes::isGlobalVariable($name, $filename, $scopeOpen);
    }

    /**
     * Resolve a set of variable tokens to the most typed object we can
     * @param int $startPtr a pointer to the first token
     * @param int $endPtr a pointer to the last token
     * @param PHP_CodeSniffer_File $phpcsFile
     * @return string a type name or a partially-resolved string, such as
     * "Foo->unknownVar->property".
     */
    protected function resolveFullVariableType($startPtr, $endPtr, $phpcsFile)
    {
        $tokens = $phpcsFile->getTokens();
        $soFar = '';
        $currPtr = $startPtr;
        do {
            list($currPtr, $nextChunk) = $this->getNextChunk($currPtr, $endPtr, $tokens);
            $soFar .= $nextChunk;
            // See if the string resolves to a type now
            $type = $this->getVariableType($startPtr, $phpcsFile, $soFar);
            if ($type !== null) {
                $soFar = $type;
            }
        } while ($currPtr <= $endPtr);
        return $soFar;
    }

    private function getNextChunk($currPtr, $endPtr, $tokens) {

        $soFar = '';
        while ($currPtr <= $endPtr) {

            $currToken = $tokens[$currPtr];

            // We treat -> as its own chunk because otherwise we are resolving
            // types with a -> prefixed, which is bad form in general and causes 
            // problems with method prefixing specifically. Unfortunately, this 
            // means that the parent function will try to resolve types ending 
            // in a ->, which should be harmless but isn't ideal.
            if ($currToken['code'] == T_PAAMAYIM_NEKUDOTAYIM || $currToken['code'] == T_OBJECT_OPERATOR) {
                // If we are at the beginning, return just the separator
                if ($soFar == '') {
                    // We normalize static invocations for simplicity
                    $soFar .= '->';
                    $currPtr = $this->stepForward($currPtr, $tokens, array(T_WHITESPACE));
                }
                break;
            }

            if ($currToken['code'] == T_OPEN_PARENTHESIS) {
                // Mark this as a function
                $soFar = '*' . $soFar;
            } else {
                // Add the token to our string
                $soFar .= $currToken['content'];
            }
            $currPtr = $this->stepForward($currPtr, $tokens, array(T_WHITESPACE));

        }
        return array($currPtr, $soFar);
    }

    /**
     * Get the start position of a variable declaration
     * @param int $varPtr a pointer to the end of the variable tokens
     * @param array $tokens the token stack
     * @return int a pointer to the first token that makes up this variable
     */
    protected function getStartOfVar($varPtr, $tokens)
    {
        while ($tokens[$varPtr]['code'] != T_WHITESPACE) {
            $varPtr--;
        }
        return $varPtr + 1;
    }

    /**
     * Get the end position of a variable declaration
     * @param int $varPtr a pointer to the start of the variable tokens
     * @param array $tokens the token stack
     * @return int a pointer to the last token that makes up this variable
     */
    protected function getEndOfVar($varPtr, $tokens)
    {
        // Tokens we expect to see in a variable
        $accept = array(T_VARIABLE, T_STRING, T_OPEN_PARENTHESIS, T_OBJECT_OPERATOR, T_PAAMAYIM_NEKUDOTAYIM);
        // Technically initialization isn't necessary, but it prevents an error
        // if we happen to call this on something that isn't a recognized var
        $prevPtr = $varPtr;
        // Look until we find a token that's not accepted
        while (in_array($tokens[$varPtr]['code'], $accept)) {
            $prevPtr = $varPtr;
            $varPtr = $this->stepForward($varPtr, $tokens, array(T_WHITESPACE));
        }
        // If our ending token is a open paren, hop over to the closer
        if ($tokens[$prevPtr]['code'] == T_OPEN_PARENTHESIS) {
            // Skip the function arguments and parentheses
            $prevPtr = $tokens[$prevPtr]['parenthesis_closer'];
        }
        return $prevPtr;
    }

    /**
     * Step forward in the token stack.
     * @param int $currPtr the beginning position in the stack
     * @param array $tokens the token stack
     * @param array $ignore an array of token codes to be ignored
     * @return int a pointer to the next token, ignoring any given types and 
     * skipping over parenthesized statements
     */
    private function stepForward($currPtr, $tokens, $ignore)
    {
        do {
            if ($tokens[$currPtr]['code'] == T_OPEN_PARENTHESIS) {
                $currPtr = $tokens[$currPtr]['parenthesis_closer'];
            }
            $currPtr++;
        } while (in_array($tokens[$currPtr]['code'], $ignore));

        return $currPtr;
    }

}
