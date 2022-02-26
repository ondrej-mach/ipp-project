#!/usr/bin/python3

import sys
import argparse
import xml.etree.ElementTree as ET
import re


class InterpretError(Exception):
    retval = 59

class InFileError(InterpretError):
    retval = 11

class MalformedXMLError(InterpretError):
    retval = 31


# Bad structure of program
class UnexpectedXMLError(InterpretError):
    retval = 32


# Undefined label, variable redefinition
class SemanticError(InterpretError):
    retval = 52


# Incompatible operand types
class RuntimeTypeError(InterpretError):
    retval = 53


# Variable does not exist
class RuntimeVariableError(InterpretError):
    retval = 54


# Frame does not exist
class RuntimeFrameError(InterpretError):
    retval = 55


# The value is missing
class RuntimeMissingValueError(InterpretError):
    retval = 56


# Bad operand value (division by zero, exit)
class RuntimeOperandError(InterpretError):
    retval = 57


# Bad string operation
class RuntimeStringError(InterpretError):
    retval = 58


class Argument:
    def __init__(self, elem: ET.Element):
        self.argtype = elem.attrib['type']

        if elem.text is None:
            self.text = ''
        else:
            self.text = elem.text


class Instruction:
    def __init__(self, elem: ET.Element):
        try:
            self.order = int(elem.attrib['order'])
            self.opcode = elem.attrib['opcode'].upper()

            for arg in elem:
                if arg.tag == 'arg1':
                    self.arg1 = Argument(arg)
                elif arg.tag == 'arg2':
                    self.arg2 = Argument(arg)
                elif arg.tag == 'arg3':
                    self.arg3 = Argument(arg)
                else:
                    raise Exception('Invalid element inside instruction')
        except:
            raise UnexpectedXMLError()


class Symbol:
    normal_types = ['int', 'string', 'bool', 'nil']
    legal_types = normal_types + ['_not_initialized']

    def __init__(self, value=None, symtype=None, interpret=False):
        if value is None and symtype is None:
            self.symtype = '_not_initialized'

        # Constructor from symbols coming directly from XML
        elif interpret:
            if symtype == 'string':
                self.value = re.sub(r'\\[0-9][0-9][0-9]', lambda esc: chr(int(esc.group()[1:])), value)
                self.symtype = symtype

            elif symtype == 'int':
                self.value = int(value)
                self.symtype = symtype

            elif symtype == 'bool':
                self.value = (value.lower() == 'true')
                self.symtype = symtype

            elif symtype == 'nil':
                if value != 'nil':
                    # not entirely correct, but specification has no error for this
                    RuntimeTypeError()

                self.value = 'nil'
                self.symtype = symtype

            else:
                raise Exception(f'Non-existent symbol type `{symtype}`')

        # If the symbols are created  internally, there is no need to parse them
        else:
            if symtype not in self.normal_types:
                raise Exception('Internal error')

            self.value = value
            self.symtype = symtype

    # used for WRITE instruction
    def __repr__(self):
        if self.symtype == '_not_initialized':
            raise RuntimeMissingValueError()
        elif self.symtype == 'string':
            return str(self.value)
        elif self.symtype == 'int':
            return str(self.value)
        elif self.symtype == 'bool':
            return 'true' if self.value else 'false'
        elif self.symtype == 'nil':
            return ''

    # used for TYPE instruction
    def getTypeString(self):
        if self.symtype in ['int', 'string', 'bool', 'nil']:
            return self.symtype
        elif self.symtype == '_not_initialized':
            return ''
        else:
            raise Exception('Internal error')

    def __eq__(self, other):
        if self.symtype == '_not_initialized' or other.symtype == '_not_initialized':
            raise RuntimeMissingValueError()

        if self.symtype == other.symtype:
            return self.value == other.value
        elif self.symtype == 'nil' or other.symtype == 'nil':
            return False
        else:
            raise RuntimeTypeError()

    def __lt__(self, other):
        if self.symtype == '_not_initialized' or other.symtype == '_not_initialized':
            raise RuntimeMissingValueError()

        if self.symtype != other.symtype:
            raise RuntimeTypeError()

        if self.symtype == 'nil' or other.symtype == 'nil':
            raise RuntimeTypeError()

        return self.value < other.value

    def __gt__(self, other):
        if self.symtype == '_not_initialized' or other.symtype == '_not_initialized':
            raise RuntimeMissingValueError()

        if self.symtype == 'nil' or other.symtype == 'nil':
            raise RuntimeTypeError()

        if self.symtype != other.symtype:
            raise RuntimeTypeError()

        return self.value > other.value


class RuntimeEnvironment:
    def __init__(self, root: ET.Element, output=sys.stdout, error=sys.stderr, input_file=sys.stdin):
        self.set_vars()

        if root.tag != 'program':
            raise UnexpectedXMLError('Bad root tag')

        if root.attrib['language'] != 'IPPcode22':
            raise UnexpectedXMLError('Bad language attribute')

        self.instructions = []
        for inst in root:
            if inst.tag != 'instruction':
                raise UnexpectedXMLError()

            self.instructions.append(Instruction(inst))
        # Sort instructions according to order attribute
        self.instructions.sort(key=lambda inst: inst.order)
        self.checkInstructionOrder()
        self.checkLabels()

        self.output = output
        self.error = error
        self.input_file = input_file

    def set_vars(self):
        self.ip = 0  # instruction pointer
        self.callstack = []
        self.stack = []
        self.gf = dict()  # global frame
        self.tf = None  # temporary frame
        self.lfstack = []  # stack of local frames
        self.end = False
        self.ret = 0

    def run_program(self):
        while not self.end:
            try:
                instruction = self.instructions[self.ip]
            # The end of the program
            except IndexError:
                return 0

            self.ip += 1
            self.execute(instruction)

        return self.ret

    def checkLabels(self):
        self.labels = dict();
        for index, inst in enumerate(self.instructions):
            if inst.opcode.upper() == 'LABEL':
                if inst.arg1.text in self.labels.keys():
                    raise SemanticError()
                else:
                    self.labels[inst.arg1.text] = index

    def checkInstructionOrder(self):
        if len(self.instructions) > 0:
            if self.instructions[0].order < 1:
                raise UnexpectedXMLError()

        for i in range(len(self.instructions) - 1):
            if self.instructions[i].order == self.instructions[i+1].order:
                raise UnexpectedXMLError()

    def getArgValue(self, arg: Argument, undef=False):
        if arg.argtype in ['int', 'string', 'bool', 'nil']:
            try:
                value = Symbol(arg.text, arg.argtype, interpret=True)
            except:
                raise UnexpectedXMLError()
            return value

        elif arg.argtype == 'var':
            return self.getVariableValue(arg.text, undef=undef)

    def variableDeclared(self, name: str):
        frame, varName = name.split('@', 1)

        try:
            if frame == 'GF':
                return varName in self.gf
            elif frame == 'TF':
                return varName in self.tf
            elif frame == 'LF':
                return varName in self.lfstack[-1]
            else:
                raise RuntimeFrameError()
        except:
            raise RuntimeFrameError()

    def getVariableValue(self, name: str, undef=False):
        if not self.variableDeclared(name):
            raise RuntimeVariableError()

        frame, varName = name.split('@', 1)

        if frame == 'GF':
            value = self.gf[varName]
        elif frame == 'TF':
            value = self.tf[varName]
        elif frame == 'LF':
            value = self.lfstack[-1][varName]

        if not undef and (value.symtype == '_not_initialized'):
            raise RuntimeMissingValueError()

        return value;

    def setVariableValue(self, name: str, val: Symbol):
        if not self.variableDeclared(name):
            raise RuntimeVariableError()

        frame, varName = name.split('@', 1)

        if frame == 'GF':
            self.gf[varName] = val
        elif frame == 'TF':
            self.tf[varName] = val
        elif frame == 'LF':
            self.lfstack[-1][varName] = val

    def goto(self, label: str):
        try:
            self.ip = self.labels[label]
        except:
            raise SemanticError()

    def labelValid(self, label):
        return label in self.labels.keys()

    def execute(self, inst: Instruction):
        if inst.opcode == 'MOVE':
            op = self.getArgValue(inst.arg2)
            self.setVariableValue(inst.arg1.text, op)

        elif inst.opcode == 'CREATEFRAME':
            self.tf = dict()

        elif inst.opcode == 'PUSHFRAME':
            if self.tf is None:
                raise RuntimeFrameError()

            self.lfstack.append(self.tf)
            self.tf = None

        elif inst.opcode == 'POPFRAME':
            try:
                self.tf = self.lfstack.pop()
            except:
                raise RuntimeFrameError()

        elif inst.opcode == 'DEFVAR':
            if self.variableDeclared(inst.arg1.text):
                raise SemanticError()

            frame, varName = inst.arg1.text.split('@', 1)

            try:
                if frame == 'GF':
                    self.gf[varName] = Symbol()
                elif frame == 'TF':
                    self.tf[varName] = Symbol()
                elif frame == 'LF':
                    self.lfstack[-1][varName] = Symbol()
                else:
                    raise RuntimeFrameError()
            except:
                raise RuntimeFrameError()

        elif inst.opcode == 'CALL':
            self.callstack.append(self.ip)
            self.goto(inst.arg1.text)

        elif inst.opcode == 'RETURN':
            try:
                self.ip = self.callstack.pop()
            except:
                raise RuntimeMissingValueError()

        elif inst.opcode == 'PUSHS':
            self.stack.append(self.getArgValue(inst.arg1))

        elif inst.opcode == 'POPS':
            try:
                self.setVariableValue(inst.arg1.text, self.stack.pop())
            except IndexError:
                raise RuntimeMissingValueError()

        elif inst.opcode in ['ADD', 'SUB', 'MUL', 'IDIV']:
            op1 = self.getArgValue(inst.arg2)
            op2 = self.getArgValue(inst.arg3)

            if op1.symtype != 'int' or op2.symtype != 'int':
                raise RuntimeTypeError()

            if inst.opcode == 'ADD':
                result = op1.value + op2.value
            elif inst.opcode == 'SUB':
                result = op1.value - op2.value
            elif inst.opcode == 'MUL':
                result = op1.value * op2.value
            elif inst.opcode == 'IDIV':
                if op2.value == 0:
                    raise RuntimeOperandError()
                result = op1.value // op2.value

            self.setVariableValue(inst.arg1.text, Symbol(result, 'int'))

        elif inst.opcode in ['LT', 'GT', 'EQ']:
            op1 = self.getArgValue(inst.arg2)
            op2 = self.getArgValue(inst.arg3)

            if inst.opcode == 'LT':
                result = op1 < op2
            elif inst.opcode == 'GT':
                result = op1 > op2
            elif inst.opcode == 'EQ':
                result = op1 == op2

            self.setVariableValue(inst.arg1.text, Symbol(result, 'bool'))

        elif inst.opcode in ['AND', 'OR']:
            op1 = self.getArgValue(inst.arg2)
            op2 = self.getArgValue(inst.arg3)

            if op1.symtype != 'bool' or op2.symtype != 'bool':
                raise RuntimeTypeError()

            if inst.opcode == 'AND':
                result = op1.value and op2.value
            elif inst.opcode == 'OR':
                result = op1.value or op2.value

            self.setVariableValue(inst.arg1.text, Symbol(result, 'bool'))

        elif inst.opcode == 'NOT':
            op = self.getArgValue(inst.arg2)

            if op.symtype != 'bool':
                raise RuntimeTypeError()

            self.setVariableValue(inst.arg1.text, Symbol(not op.value, 'bool'))

        elif inst.opcode == 'INT2CHAR':
            op = self.getArgValue(inst.arg2)

            if op.symtype != 'int':
                raise RuntimeTypeError()

            try:
                result = chr(op.value)
            except:
                raise RuntimeStringError()

            self.setVariableValue(inst.arg1.text, Symbol(result, 'string'))

        elif inst.opcode == 'STRI2INT':
            string = self.getArgValue(inst.arg2)
            pos = self.getArgValue(inst.arg3)

            if string.symtype != 'string' or pos.symtype != 'int':
                raise RuntimeTypeError()

            if pos.value < 0:
                raise RuntimeStringError()

            try:
                result = ord(string.value[pos.value])
            except:
                raise RuntimeStringError()

            self.setVariableValue(inst.arg1.text, Symbol(result, 'int'))

        elif inst.opcode == 'READ':
            op = ''
            symtype = inst.arg2.text
            input_equivalent = ''
            failed = False

            try:
                # Do exactly what the input() function does
                try:
                    line = self.input_file.readline()
                except:
                    raise InFileError()

                if line:
                    input_equivalent = line.rstrip()
                else:
                    raise ValueError

                if symtype == 'int':
                    op = int(input_equivalent)
                elif symtype == 'string':
                    op = str(input_equivalent)
                elif symtype == 'bool':
                    op = str(input_equivalent).lower() == 'true'
                else:
                    raise UnexpectedXMLError('Cannot read this type')

            except ValueError:
                op = 'nil'
                symtype = 'nil'


            result = Symbol(op, symtype, interpret=False)
            self.setVariableValue(inst.arg1.text, result)

        elif inst.opcode == 'WRITE':
            print(self.getArgValue(inst.arg1), file=self.output, end='')

        elif inst.opcode == 'CONCAT':
            op1 = self.getArgValue(inst.arg2)
            op2 = self.getArgValue(inst.arg3)

            if op1.symtype != 'string' or op2.symtype != 'string':
                raise RuntimeTypeError()

            result = op1.value + op2.value
            self.setVariableValue(inst.arg1.text, Symbol(result, 'string'))

        elif inst.opcode == 'STRLEN':
            op = self.getArgValue(inst.arg2)

            if op.symtype != 'string':
                raise RuntimeTypeError()

            self.setVariableValue(inst.arg1.text, Symbol(len(op.value), 'int'))

        elif inst.opcode == 'GETCHAR':
            string = self.getArgValue(inst.arg2)
            pos = self.getArgValue(inst.arg3)

            if string.symtype != 'string' or pos.symtype != 'int':
                raise RuntimeTypeError()

            if pos.value < 0:
                raise RuntimeStringError()

            try:
                result = string.value[pos.value]
            except:
                raise RuntimeStringError()

            self.setVariableValue(inst.arg1.text, Symbol(result, 'string'))

        elif inst.opcode == 'SETCHAR':
            dest_str = self.getArgValue(inst.arg1)
            dest_pos = self.getArgValue(inst.arg2)
            src_str = self.getArgValue(inst.arg3)

            if dest_str.symtype != 'string' or src_str.symtype != 'string' or dest_pos.symtype != 'int':
                raise RuntimeTypeError()

            if dest_pos.value < 0:
                raise RuntimeStringError()

            try:
                modifiable = list(dest_str.value)
                modifiable[dest_pos.value] = src_str.value[0]
                dest_str.value = ''.join(modifiable)
            except:
                raise RuntimeStringError()

            self.setVariableValue(inst.arg1.text, dest_str)

        elif inst.opcode == 'TYPE':
            op = self.getArgValue(inst.arg2, undef=True)

            result = op.symtype if op.symtype in Symbol.normal_types else ''
            self.setVariableValue(inst.arg1.text, Symbol(result, 'string'))

        elif inst.opcode == 'LABEL':
            pass

        elif inst.opcode == 'JUMP':
            self.goto(inst.arg1.text)

        elif inst.opcode in ['JUMPIFEQ', 'JUMPIFNEQ']:
            op1 = self.getArgValue(inst.arg2)
            op2 = self.getArgValue(inst.arg3)

            if not self.labelValid(inst.arg1.text):
                raise SemanticError()

            # True or False, according to opcode
            jumpif = inst.opcode == 'JUMPIFEQ'
            result = op1 == op2

            if result == jumpif:
                self.goto(inst.arg1.text)

        elif inst.opcode == 'EXIT':
            op = self.getArgValue(inst.arg1)

            if op.symtype != 'int':
                raise RuntimeTypeError()

            if op.value not in range(0, 50):
                raise RuntimeOperandError()

            self.ret = op.value
            self.end = True

        elif inst.opcode == 'DPRINT':
            print(self.getArgValue(inst.arg1), file=self.error, end='')

        else:
            raise UnexpectedXMLError(f'Invalid opcode `{inst.opcode}`')


def main():
    parser = argparse.ArgumentParser(description='IPPcode22 XML interpreter')
    parser.add_argument('-s', '--source', type=str, help='Source XML file to interpret')
    parser.add_argument('-i', '--input', type=str, help='Input of the interpreted program')

    args = parser.parse_args()

    if args.source is None and args.input is None:
        sys.stderr.write('At least one of the arguments is required.\n')
        return 10

    if args.source:
        try:
            xml_file = open(args.source, 'r')
            xml_str = xml_file.read()
            xml_file.close()
        except:
            sys.stderr.write('Source file cannot be opened.\n')
            return 11
    else:
        xml_str = sys.stdin.read()

    try:
        root = ET.fromstring(xml_str)
    except:
        sys.stderr.write('Invalid XML format, cannot parse.\n')
        return MalformedXMLError.retval

    if args.input:
        try:
            input_file = open(args.input, 'r')
        except:
            sys.stderr.write('Input file cannot be opened.\n')
            return 11
    else:
        input_file = sys.stdin

    try:
        rtenv = RuntimeEnvironment(root, input_file=input_file)
        return rtenv.run_program()

    except InterpretError as e:
        sys.stderr.write('Bad program structure.\n')
        return e.retval

    except AttributeError:
        sys.stderr.write('Bad program structure.\n')
        return UnexpectedXMLError.retval

if __name__ == '__main__':
    sys.exit(main())
