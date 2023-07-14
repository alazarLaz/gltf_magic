<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;


define('UID_MAX_LENGTH', 10);
define('USHF_MAX_LENGTH', 10);
define('TIMESTAMP_MAX_LENGTH', 10);
define('TIMESTAMP_VALIDITY_MAX_LENGTH', 5);
define('SHFL_MATRIX_SIZE', 6 * 5);


function decodeGltfAndToken($egltf, $etkn)
{
    // Copy-pasted offset extracting block from the encoder function. One source of truth.
    $headerLength = UID_MAX_LENGTH + USHF_MAX_LENGTH + TIMESTAMP_MAX_LENGTH + TIMESTAMP_VALIDITY_MAX_LENGTH;
    $unshOffsetList = [];
    for ($i = 0; $i < USHF_MAX_LENGTH; $i++) {
        $unshOffset = b602num($etkn[$headerLength + b602num($etkn[$i + UID_MAX_LENGTH])]) % 30;
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
    for ($i = 0; $i < UID_MAX_LENGTH; $i++) {
        $uidDec = strval(b602num($etkn[$headerLength + b602num($etkn[$i])]) % 10);
        $decUID .= $uidDec;
    }
    $decUID = strval(intval(strrev($decUID)));

    // Decode UNIX timestamp.
    $decTstp = '';
    for ($i = 0; $i < TIMESTAMP_MAX_LENGTH; $i++) {
        $tstpDec = strval(b602num($etkn[$headerLength + b602num($etkn[$i + UID_MAX_LENGTH + USHF_MAX_LENGTH])]) % 10);
        $decTstp .= $tstpDec;
    }
    $decTstp = strval(intval(strrev($decTstp)));

    // Decode UNIX timestamp validity interval.
    $decTstpVal = '';
    for ($i = 0; $i < TIMESTAMP_VALIDITY_MAX_LENGTH; $i++) {
        $tstpValDec = strval(b602num($etkn[$headerLength + b602num($etkn[$i + UID_MAX_LENGTH + USHF_MAX_LENGTH + TIMESTAMP_MAX_LENGTH])]) % 10);
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


function encodeAndGenerateToken($sgltf, $uid, $ushf, $tsv)
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
    $token = "";

    // UNIX timestamp as 10-decimal int seconds.
    $thisMoment = time();

    // Generate 10 random unique indices for UID, 10 more for shuffling sequence, 10 more for the timestamp, and
    // 5 for TS_validity, in range 0:60. UID, SHF seq, Timestamp and TS_validity interval will be written in those
    // positions within the token payload area. Meaningful field lengths may be of different length, but once chosen
    // we should stick to those for preserving the compatibility between encoder and various decoding viewers.
    // Length constants as defined at the beginning of this script should suffice for the foreseeable future.
    $headerLength = UID_MAX_LENGTH + USHF_MAX_LENGTH + TIMESTAMP_MAX_LENGTH + TIMESTAMP_VALIDITY_MAX_LENGTH;
    $idxs = array_rand(range(0, 59), $headerLength);

    // Write indexes of meaningful BASE60 symbols of the token payload area into the token header.
    foreach ($idxs as $idx) {
        $token .= num2b60($idx);
    }

    // Fill the token payload with blanks. Token payload is 60 ASCII characters long. We do this because
    // we need positions in the token string to exist in order to replace them with symbols later.
    // There are other more elegant ways for filling the payload, but this one is quite simple.
    // Remember that we add yet another blank at the end to make token 96 characters long, thus "61".
    $token .= str_repeat(".", 61);

    // Write symbols representing UID decimals one by one into formerly randomly chosen positions.
    $uidTemp = str_pad($uid, UID_MAX_LENGTH, "0", STR_PAD_LEFT);
    for ($i = 0; $i < UID_MAX_LENGTH; $i++) {
        $newToken = substr($token, 0, $headerLength + $idxs[$i]);
        $newToken .= num2b60(intval(substr($uidTemp, -1)) + (random_int(0, 5) * 10));
        $newToken .= substr($token, $headerLength + $idxs[$i] + 1);
        $token = $newToken;
        $uidTemp = substr($uidTemp, 0, -1);
    }

    // Write symbols representing a unique Shuffling sequence for this particular user.
    // Matrix shuffling positions are in range 0 to 29 for a 6x5 matrix.
    $idxOffset = UID_MAX_LENGTH;
    for ($i = 0; $i < USHF_MAX_LENGTH; $i++) {
        $newToken = substr($token, 0, $headerLength + $idxs[$i + $idxOffset]);
        $newToken .= num2b60(intval($ushf[USHF_MAX_LENGTH - $i - 1]) + (random_int(0, 1) * 30));
        $newToken .= substr($token, $headerLength + $idxs[$i + $idxOffset] + 1);
        $token = $newToken;
    }

    // Write symbols representing Timestamp decimals.
    $idxOffset += USHF_MAX_LENGTH;
    $thisMomentTemp = strval($thisMoment);
    for ($i = 0; $i < TIMESTAMP_MAX_LENGTH; $i++) {
        $newToken = substr($token, 0, $headerLength + $idxs[$i + $idxOffset]);
        $newToken .= num2b60(intval(substr($thisMomentTemp, -1)) + (random_int(0, 5) * 10));
        $newToken .= substr($token, $headerLength + $idxs[$i + $idxOffset] + 1);
        $token = $newToken;
        $thisMomentTemp = substr($thisMomentTemp, 0, -1);
    }

    // Write symbols representing Timestamp validity decimals.
    $idxOffset += UID_MAX_LENGTH;
    $tsvTemp = str_pad($tsv, TIMESTAMP_VALIDITY_MAX_LENGTH, "0", STR_PAD_LEFT);
    for ($i = 0; $i < TIMESTAMP_VALIDITY_MAX_LENGTH; $i++) {
        $newToken = substr($token, 0, $headerLength + $idxs[$i + $idxOffset]);
        $newToken .= num2b60(intval(substr($tsvTemp, -1)) + (random_int(0, 5) * 10));
        $newToken .= substr($token, $headerLength + $idxs[$i + $idxOffset] + 1);
        $token = $newToken;
        $tsvTemp = substr($tsvTemp, 0, -1);
    }

    // Fill the remaining blank spaces with randomly generated BASE60 symbols.
    for ($i = $headerLength; $i < strlen($token); $i++) {
        if ($token[$i] == '.') {
            $newToken = substr($token, 0, $i);
            $newToken .= num2b60(random_int(0, 59));
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
    $sEncKey = str_pad($sgltf["accessors"][0]["count"], USHF_MAX_LENGTH, "0", STR_PAD_LEFT);

    // Write hidden key digits into the shuffling matrix.
    $shOffsetList = [];
    for ($i = 0; $i < USHF_MAX_LENGTH; $i++) {
        $shOffset = b602num($token[$headerLength + b602num($token[$i + UID_MAX_LENGTH])]) % 30;
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
    // 15 i.e. 15//5 = row 3+1 (because we count rows starting from 0), 15%5 = column 0+1 (-//- counting columns from 0).
    // Digit 3 will be written to matrix location 7 i.e. 2//5 = row 0+1, and 2%5 = column 2+1 and so on.

    // Fill unused spaces in shuffling matrix with random decimals.
    for ($i = 0; $i < SHFL_MATRIX_SIZE; $i++) {
        if (!in_array($i, $shOffsetList)) {
            $shMat[intdiv($i, 5)][($i % 5)] = random_int(0, 9);
        }
    }

    // Construct encoded version of the original GLTF file by swapping some least important digits in two float fields.
    $egltf = $sgltf;

    // Delete key values from source GLTF.
    $egltf["accessors"][0]["count"] = 1;
    $egltf["accessors"][1]["count"] = 1;
    $egltf["accessors"][2]["count"] = 1;

    for ($i = 0; $i < 3; $i++) {
        $sVal = strval($sgltf["accessors"][0]["max"][$i]);
        $sEncVal = substr($sVal, 0, -6);
        for ($j = 0; $j < 5; $j++) {
            $sEncVal .= strval($shMat[$i][$j]);
        }
        $sEncVal .= substr($sVal, -1);
        $egltf["accessors"][0]["max"][$i] = floatval($sEncVal);
    }

    for ($i = 0; $i < 3; $i++) {
        $sVal = strval($sgltf["accessors"][0]["min"][$i]);
        $sEncVal = substr($sVal, 0, -6);
        for ($j = 0; $j < 5; $j++) {
            $sEncVal .= strval($shMat[$i + 3][$j]);
        }
        $sEncVal .= substr($sVal, -1);
        $egltf["accessors"][0]["min"][$i] = floatval($sEncVal);
    }

    // Convert PHP structure to JSON, i.e., GLTF.
    $egltf = json_encode($egltf);

    return [$egltf, $token];
}


function num2b60($num)
{
    if ($num > 59 || $num < 0) {
        echo 'BASE60 encoder received number out of range: ' . $num;
    }

    if ($num >= 52) {
        return chr($num - 52 + 50);
    } else {
        if ($num >= 26) {
            return chr($num - 26 + 97);
        } else {
            return chr($num + 65);
        }
    }
}

function b602num($asc)
{
    if (strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz23456789', $asc) === false) {
        echo 'BASE60 decoder received symbol out of range: ' . $asc;
    }

    $num = ord($asc);
    if ($num >= 97) {
        return $num - 97 + 26;
    } else {
        if ($num >= 65) {
            return $num - 65;
        } else {
            return $num - 50 + 52;
        }
    }
}


class GLTFController extends Controller
{

    // Handle file upload
    public function uploadFile(Request $request)
    {
        // Check if the request has a file
        if ($request->hasFile('gltf_file')) {
            $file = $request->file('gltf_file');

            // Validate the file
            $validated = $request->validate([
                'gltf_file' => 'required|mimes:gltf|max:2048',
            ]);

            // Store the file in a temporary location
            $path = $file->store('tmp', 'public');

            // Return the file path for further processing
            return response()->json(['file_path' => $path]);
        }

        // If no file is uploaded or validation fails, return an error response
        return response()->json(['error' => 'Invalid file'], 400);
    }




    // Encode GLTF and generate token
    public function encodeGLTF(Request $request)
    {
        // Get the file path from the request
        $filePath = $request->input('file_path');

        // Check if the file path is valid
        if (!empty($filePath) && Storage::disk('public')->exists($filePath)) {
            // Read the GLTF file from the storage
            $gltfJson = Storage::disk('public')->get($filePath);

            // Convert the GLTF JSON to a PHP object
            $gltfData = json_decode($gltfJson, true);

            // Generate a token (replace the following variables with your own values)
            $uID = 123456789;
            $ushfMaxLength = 30;
            $shfSeq = array_rand(range(0, $ushfMaxLength - 1), $ushfMaxLength);
            $tsVal = 30;
            list($encodedGltf, $generatedToken) = encodeAndGenerateToken($gltfData, $uID, $shfSeq, $tsVal);

            // Generate a file name for the encoded GLTF
            $encodedFileName = pathinfo($filePath, PATHINFO_FILENAME) . "_encoded.gltf";

            // Store the encoded GLTF in the storage
            Storage::disk('public')->put($encodedFileName, $encodedGltf);

            // Return the generated token and encoded file name
            return response()->json([
                'token' => $generatedToken,
                'encoded_file' => $encodedFileName,
            ]);
        }

        // If the file path is invalid, return an error response
        return response()->json(['error' => 'Invalid file path'], 400);
    }

    // Decode encoded GLTF using token
    public function decodeGLTF(Request $request)
    {
        // Get the file paths and token from the request
        $encodedFilePath = $request->input('encoded_file_path');
        $token = $request->input('token');

        // Check if the file paths and token are valid
        if (
            !empty($encodedFilePath) && !empty($token) &&
            Storage::disk('public')->exists($encodedFilePath)
        ) {
            // Read the encoded GLTF file from the storage
            $encodedGltfJson = Storage::disk('public')->get($encodedFilePath);

            // Convert the encoded GLTF JSON to a PHP object
            $encodedGltfData = json_decode($encodedGltfJson, true);

            // Decode the GLTF using the token
            list($decodedGltf, $decodedKey, $decodedUID, $decodedTimestamp, $decodedValidity) =
                decodeGltfAndToken($encodedGltfData, $token);

            // Generate a file name for the decoded GLTF
            $decodedFileName = pathinfo($encodedFilePath, PATHINFO_FILENAME) . "_decoded.gltf";

            // Store the decoded GLTF in the storage
            Storage::disk('public')->put($decodedFileName, $decodedGltf);

            // Return the decoded key, user ID, timestamp, and validity, and the decoded file name
            return response()->json([
                'decoded_key' => $decodedKey,
                'decoded_uid' => $decodedUID,
                'decoded_timestamp' => $decodedTimestamp,
                'decoded_validity' => $decodedValidity,
                'decoded_file' => $decodedFileName,
            ]);
        }

        // If the file paths or token are invalid, return an error response
        return response()->json(['error' => 'Invalid file paths or token'], 400);
    }
}