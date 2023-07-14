<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GLTFController extends Controller
{
    private const UID_MAX_LENGTH = 10;
    private const USHF_MAX_LENGTH = 10;
    private const TIMESTAMP_MAX_LENGTH = 10;
    private const TIMESTAMP_VALIDITY_MAX_LENGTH = 5;
    private const SHFL_MATRIX_SIZE = 6 * 5;
    private const ENCRYPTED_FILES_PATH = 'encrypted';

    public function upload(Request $request)
    {
        // Check if the request has a file
        if ($request->hasFile('gltf_file')) {
            $file = $request->file('gltf_file');

            // Validate the file
            $validated = $request->validate([
                'gltf_file' => 'file',
            ]);

            // Encrypt the file
            $encryptedPath = $this->encryptGLTFFile($file);

            // Return the encrypted file path for further processing
            return response()->json(['encrypted_path' => $encryptedPath]);
        }

        // If no file is uploaded or validation fails, return an error response
        return response()->json(['error' => 'Invalid file'], 400);
    }

    public function decrypt(Request $request)
{
    $encryptedFilePath = $request->input('encrypted_file');

    // Decrypt the file
    $decryptedContents = $this->decryptGLTFFile($encryptedFilePath);

    // Generate a random file name for the decrypted file
    $decryptedFileName = Str::random(10) . '.gltf';

    // Store the decrypted file in a temporary directory
    $tempPath = sys_get_temp_dir() . '/' . $decryptedFileName;
    file_put_contents($tempPath, $decryptedContents);

    // Initiate a file download for the decrypted file
    return response()->streamDownload(function () use ($tempPath) {
        echo file_get_contents($tempPath);
        unlink($tempPath); // Delete the temporary file after sending the response
    }, $decryptedFileName);
}
    private function encryptGLTFFile($file)
    {
        // Generate a random file name for the encrypted GLTF file
        $encryptedFileName = Str::random(10) . '.gltf';

        // Get the contents of the GLTF file
        $gltfContents = file_get_contents($file->path());

        // Encrypt the GLTF file contents (replace this with your encryption logic)
        $encryptedContents = encrypt($gltfContents);

        // Store the encrypted file in the desired location
        Storage::disk('public')->put(self::ENCRYPTED_FILES_PATH . '/' . $encryptedFileName, $encryptedContents);

        // Return the path of the encrypted file for further processing
        return self::ENCRYPTED_FILES_PATH . '/' . $encryptedFileName;
    }

    private function decryptGLTFFile($encryptedFilePath)
    {
        // Get the encrypted file contents
        $encryptedContents = Storage::disk('public')->get($encryptedFilePath);

        // Decrypt the file contents (replace this with your decryption logic)
        $decryptedContents = decrypt($encryptedContents);

        return $decryptedContents;
    }

    private function decodeGltfAndToken($egltf, $etkn)
    {
        // Copy-pasted offset extracting block from the encoder function. One source of truth.
        $headerLength = self::UID_MAX_LENGTH + self::USHF_MAX_LENGTH + self::TIMESTAMP_MAX_LENGTH + self::TIMESTAMP_VALIDITY_MAX_LENGTH;
        $unshOffsetList = [];
        for ($i = 0; $i < self::USHF_MAX_LENGTH; $i++) {
            $unshOffset = $this->b602num($etkn[$headerLength + $this->b602num($etkn[$i + self::UID_MAX_LENGTH])]) % 30;
            $unshOffsetList[] = $unshOffset;
        }

        // Transcribe data from encoded GLTF into a matrix form.
        $unshMatrix = [[0, 0, 0, 0, 0], [0, 0, 0, 0, 0], [0, 0, 0, 0, 0], [0, 0, 0, 0, 0], [0, 0, 0, 0, 0], [0, 0, 0, 0, 0]];
        for ($i = 0; $i < 3; $i++) {
            $encVal = substr(strval($egltf["accessors"][0]["max"][$i]), -6, -1);
            for ($j = 0; $j < 5; $j++) {
                $unshMatrix[$i][$j] = intval($encVal[$j]);
            }
        }
        for ($i = 0; $i < 3; $i++) {
            $encVal = substr(strval($egltf["accessors"][0]["min"][$i]), -6, -1);
            for ($j = 0; $j < 5; $j++) {
                $unshMatrix[$i + 3][$j] = intval($encVal[$j]);
            }
        }

        // Extract matrix shuffling offsets from token and reconstruct the missing key value.
        $decKey = '';
        foreach ($unshOffsetList as $offset) {
            $decKey .= strval($unshMatrix[intdiv($offset, 5)][($offset % 5)]);
        }
        $decKey = strval(intval($decKey));

        // Decode user ID.
        $decUID = '';
        for ($i = 0; $i < self::UID_MAX_LENGTH; $i++) {
            $uidDec = strval($this->b602num($etkn[$headerLength + $this->b602num($etkn[$i])]) % 10);
            $decUID .= $uidDec;
        }
        $decUID = strval(intval(strrev($decUID)));

        // Decode UNIX timestamp.
        $decTstp = '';
        for ($i = 0; $i < self::TIMESTAMP_MAX_LENGTH; $i++) {
            $tstpDec = strval($this->b602num($etkn[$headerLength + $this->b602num($etkn[$i + self::UID_MAX_LENGTH + self::USHF_MAX_LENGTH])]) % 10);
            $decTstp .= $tstpDec;
        }
        $decTstp = strval(intval(strrev($decTstp)));

        // Decode UNIX timestamp validity interval.
        $decTstpVal = '';
        for ($i = 0; $i < self::TIMESTAMP_VALIDITY_MAX_LENGTH; $i++) {
            $tstpValDec = strval($this->b602num($etkn[$headerLength + $this->b602num($etkn[$i + self::UID_MAX_LENGTH + self::USHF_MAX_LENGTH + self::TIMESTAMP_MAX_LENGTH])]) % 10);
            $decTstpVal .= $tstpValDec;
        }
        $decTstpVal = strval(intval(strrev($decTstpVal)));

        // Write decoded hidden key where it belongs.
        $decGltf = $egltf;
        $decGltf["accessors"][0]["count"] = intval($decKey);
        $decGltf["accessors"][1]["count"] = intval($decKey);
        $decGltf["accessors"][2]["count"] = intval($decKey);

        return [json_encode($decGltf), $decKey, $decUID, $decTstp, $decTstpVal];
    }

    private function encodeAndGenerateToken($sgltf, $uid, $ushf, $tsv)
    {
        // Token generation ----------------------------------------------------------------------------

        // Token is a string 96 characters long containing BASE60 symbols.

        // We use custom BASE60 encoding because we decided that token payload will have 60 ASCII symbols in it,
        // and that we will record meaningful positions of those in the form of same-base ASCII symbols inside
        // the token header at its beginning.
        // Thus token will be 35 + 60 BASE60 ASCII characters long (if we assume that we will need 35 meaningful
        // positions in the payload, so the same number of index offset symbols inside the header is necessary).
        // We actually add yet another blank to the end to make token 96 characters long, for obfuscating purposes.
        // Some of the payload symbols are meaningful such as UID decimals, the rest is filled with random garbage.
        $token = '';

        // UNIX timestamp as 10-decimal int seconds.
        $thisMoment = time();

        // Generate 10 random unique indices for UID, 10 more for shuffling sequence, 10 more for the timestamp, and
        // 5 for TS_validity, in range 0:60. UID, SHF seq, Timestamp and TS_validity interval will be written in those
        // positions within the token payload area. Meaningful field lengths may be of different length, but once chosen
        // we should stick to those for preserving the compatibility between encoder and various decoding viewers.
        // Length constants as defined at the beginning of this script should suffice for the foreseeable future.
        $headerLength = self::UID_MAX_LENGTH + self::USHF_MAX_LENGTH + self::TIMESTAMP_MAX_LENGTH + self::TIMESTAMP_VALIDITY_MAX_LENGTH;
        $idxs = array_rand(range(0, 59), $headerLength);

        // Write indexes of meaningful BASE60 symbols of the token payload area into the token header.
        foreach ($idxs as $idx) {
            $token .= $this->num2b60($idx);
        }

        // Fill the token payload with blanks. Token payload is 60 ASCII characters long. We do this because
        // we need positions in the token string to exist in order to replace them with symbols later.
        // There are other more elegant ways for filling the payload, but this one is quite simple.
        // Remember that we add yet another blank at the end to make token 96 characters long, thus "61".
        $token .= str_repeat('.', 61);

        // Write symbols representing UID decimals one by one into formerly randomly chosen positions.
        $uidTemp = str_pad($uid, self::UID_MAX_LENGTH, '0', STR_PAD_LEFT);
        for ($i = 0; $i < self::UID_MAX_LENGTH; $i++) {
            $newToken = substr($token, 0, $headerLength + $idxs[$i]);
            $newToken .= $this->num2b60(intval(substr($uidTemp, -1)) + (random_int(0, 5) * 10));
            $newToken .= substr($token, $headerLength + $idxs[$i] + 1);
            $token = $newToken;
            $uidTemp = substr($uidTemp, 0, -1);
        }

        // Write symbols representing a unique Shuffling sequence for this particular user.
        // Matrix shuffling positions are in range 0 to 29 for a 6x5 matrix.
        $idxOffset = self::UID_MAX_LENGTH;
        for ($i = 0; $i < self::USHF_MAX_LENGTH; $i++) {
            $newToken = substr($token, 0, $headerLength + $idxs[$i + $idxOffset]);
            $newToken .= $this->num2b60(intval($ushf[self::USHF_MAX_LENGTH - $i - 1]) + (random_int(0, 1) * 30));
            $newToken .= substr($token, $headerLength + $idxs[$i + $idxOffset] + 1);
            $token = $newToken;
        }

        // Write symbols representing Timestamp decimals.
        $idxOffset += self::USHF_MAX_LENGTH;
        $thisMomentTemp = strval($thisMoment);
        for ($i = 0; $i < self::TIMESTAMP_MAX_LENGTH; $i++) {
            $newToken = substr($token, 0, $headerLength + $idxs[$i + $idxOffset]);
            $newToken .= $this->num2b60(intval(substr($thisMomentTemp, -1)) + (random_int(0, 5) * 10));
            $newToken .= substr($token, $headerLength + $idxs[$i + $idxOffset] + 1);
            $token = $newToken;
            $thisMomentTemp = substr($thisMomentTemp, 0, -1);
        }

        // Write symbols representing Timestamp validity decimals.
        $idxOffset += self::UID_MAX_LENGTH;
        $tsvTemp = str_pad($tsv, self::TIMESTAMP_VALIDITY_MAX_LENGTH, '0', STR_PAD_LEFT);
        for ($i = 0; $i < self::TIMESTAMP_VALIDITY_MAX_LENGTH; $i++) {
            $newToken = substr($token, 0, $headerLength + $idxs[$i + $idxOffset]);
            $newToken .= $this->num2b60(intval(substr($tsvTemp, -1)) + (random_int(0, 5) * 10));
            $newToken .= substr($token, $headerLength + $idxs[$i + $idxOffset] + 1);
            $token = $newToken;
            $tsvTemp = substr($tsvTemp, 0, -1);
        }

        // Fill the remaining blank spaces with randomly generated BASE60 symbols.
        for ($i = $headerLength; $i < strlen($token); $i++) {
            if ($token[$i] == '.') {
                $newToken = substr($token, 0, $i);
                $newToken .= $this->num2b60(random_int(0, 59));
                $newToken .= substr($token, $i + 1);
                $token = $newToken;
            }
        }

        // GLTF encoding -------------------------------------------------------------------------------

        // Encoded GLTF file has crucial int numerical values deleted. Those digits are shuffled and written
        // in matrix form, embedded into least significant digits of several nearby float point arrays.
        // Shuffling sequence is carried in a token; it can be kept constant with respect to user ID, or random.

        // Construct empty shuffling matrix. It has 6 rows and 5 columns. In basic non-NumPy Python we can use
        // a 1-D list of 1-D lists. This lack of typical scientific/numeric notation is a consequence of the fact
        // that Python had been conceived as a handy text-processing tool. In other programming languages different
        // notations might be more appropriate and easier to use.
        $shMat = [[0, 0, 0, 0, 0], [0, 0, 0, 0, 0], [0, 0, 0, 0, 0], [0, 0, 0, 0, 0], [0, 0, 0, 0, 0], [0, 0, 0, 0, 0]];

        // Fill empty leading spaces in data shorter than max length with zeros. Decoder must spot those zeros in matrix.
        $sEncKey = str_pad($sgltf["accessors"][0]["count"], self::USHF_MAX_LENGTH, '0', STR_PAD_LEFT);

        // Write hidden key digits into the shuffling matrix.
        $shOffsetList = [];
        for ($i = 0; $i < self::USHF_MAX_LENGTH; $i++) {
            $shOffset = $this->b602num($token[$headerLength + $this->b602num($token[$i + self::UID_MAX_LENGTH])]) % 30;
            $shOffsetList[] = $shOffset;
            $shMat[intdiv($shOffset, 5)][($shOffset % 5)] = intval($sEncKey[$i]);
        }

        // Observe the sequence in which hidden key digits are written into the matrix: First value in shuffling sequence
        // encodes place in which to write the least significant hidden key digit, second value from the sequence encodes
        // place for next-to-last hidden key digit and so on.
        //
        // In other words, think of decimals as being written in reverse in comparison to left-to-right human-readable order.
        // For consistency reasons, we write shuffling values in reverse too, i.e value at last index in shfSeq[] goes first.
        // 
        // For example, if encKey = 1234 and shMat = [15, 2, 19, 7], then the last digit 4 will be written to matrix location
        // 15 i.e. 2nd column, 1st row, next-to-last digit 3 will be written to matrix location 2, etc.
        // Why reverse? Because we will read those digits starting from the back later. Check the decoding algorithm.
        $encMat = '';
        for ($i = 0; $i < 3; $i++) {
            $encMat .= substr($shMat[$i], 0, 5);
        }
        for ($i = 3; $i < 6; $i++) {
            $encMat .= substr($shMat[$i], 0, 5);
        }

        // Reconstruct the missing hidden key and check if it has been read correctly.
        $recKey = '';
        foreach ($shOffsetList as $offset) {
            $recKey .= strval($shMat[intdiv($offset, 5)][($offset % 5)]);
        }
        $recKey = strval(intval($recKey));

        // Return the encoded GLTF, token, and reconstructed key.
        $sgltf["accessors"][0]["count"] = $encMat;
        $sgltf["accessors"][1]["count"] = $encMat;
        $sgltf["accessors"][2]["count"] = $encMat;

        return [json_encode($sgltf), $token, $recKey];
    }

    private function b602num($x)
    {
        // A - Z  (26 chars),  a - z  (26 chars),  0 - 9 (10 chars)
        // BASE60 encoding, with slight modification.
        // Treat letters and numbers as ASCII codes minus 10 or 65
        // Thus, there is no need for character encoding/decoding,
        // and this part of the code is very fast in Python.
        $xNum = ord($x);
        if ($xNum < 58) {
            $xNum -= 48;
        } elseif ($xNum < 91) {
            $xNum -= 55;
        } else {
            $xNum -= 61;
        }

        return $xNum;
    }

    private function num2b60($x)
    {
        // Reverse of the function above.
        $x = intval($x);
        if ($x < 10) {
            $x += 48;
        } elseif ($x < 36) {
            $x += 55;
        } else {
            $x += 61;
        }

        return chr($x);
    }

    public function decode(Request $request)
    {
        $egltf = json_decode($request->input('egltf'), true);
        $etkn = $request->input('etkn');

        [$decGltf, $decKey, $decUID, $decTstp, $decTstpVal] = $this->decodeGltfAndToken($egltf, $etkn);

        $result = [
            'decGltf' => $decGltf,
            'decKey' => $decKey,
            'decUID' => $decUID,
            'decTstp' => $decTstp,
            'decTstpVal' => $decTstpVal
        ];

        return response()->json($result);
    }

    public function encode(Request $request)
    {
        $sgltf = json_decode($request->input('sgltf'), true);
        $uid = $request->input('uid');
        $ushf = $request->input('ushf');
        $tsv = $request->input('tsv');

        [$encGltf, $token, $recKey] = $this->encodeAndGenerateToken($sgltf, $uid, $ushf, $tsv);

        $result = [
            'encGltf' => $encGltf,
            'token' => $token,
            'recKey' => $recKey
        ];

        return response()->json($result);
    }
}