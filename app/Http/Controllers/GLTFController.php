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

    private function num2b60($num)
    {
        $base60Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

        $num = $num % 60; // Wrap the number within the range of 0 to 59

        if ($num >= 0 && $num <= 59) {
            if ($num >= 52) {
                return $base60Chars[$num - 52 + 50];
            } elseif ($num >= 26) {
                return $base60Chars[$num - 26 + 26];
            } else {
                return $base60Chars[$num];
            }
        } else {
            // Report an error for numbers outside the valid range
            throw new \Exception('Error: BASE60 encoder received number out of range: ' . $num);
        }
    }

    private function b602num($char)
    {
        $base60Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $num = strpos($base60Chars, $char);
        if ($num === false) {
            echo 'Error: Invalid BASE60 character: ' . $char;
            return -1;
        }
        return $num;
    }

    public function showUploadForm()
    {
        return view('upload');
    }

    public function showDecryptForm()
    {
        return view('decrypt');
    }


    public function decrypt(Request $request)
    {
        // Check if the request has a file
        if ($request->hasFile('encrypted_gltf_file') && $request->has('token')) {
            $encryptedFile = $request->file('encrypted_gltf_file');
            $token = $request->input('token');

            // Validate the file and token (You can add more validation here if needed)
            $validated = $request->validate([
                'encrypted_gltf_file' => 'file',
                'token' => 'required|string',
            ]);

            // Read the content of the encrypted GLTF file
            $encryptedContent = file_get_contents($encryptedFile->getPathname());

            // Decode the GLTF file and token
            list($decodedGltf, $decryptedKey, $decryptedUID, $decryptedTimestamp, $decryptedValidity) = $this->decodeGltfAndToken($encryptedContent, $token);

            // Return the decrypted GLTF and other decoded information
            return response()->json([
                'decoded_gltf' => $decodedGltf,
                'decrypted_key' => $decryptedKey,
                'decrypted_uid' => $decryptedUID,
                'decrypted_timestamp' => $decryptedTimestamp,
                'decrypted_validity' => $decryptedValidity,
            ]);
        }

        // If the file or token is missing or validation fails, return an error response
        return response()->json(['error' => 'Invalid file or token'], 400);
    }

    public function encrypt(Request $request)
    {
        // Check if the request has a file
        if ($request->hasFile('gltf_file')) {
            $file = $request->file('gltf_file');

            // Validate the file (You can add more validation here if needed)
            $validated = $request->validate([
                'gltf_file' => 'file',
            ]);

            // Encrypt the file and generate token
            list($encodedGltf, $generatedToken) = $this->encodeAndGenerateToken($file);

            // Save the encoded GLTF to the disk
            $encodedFilepath = Str::beforeLast($file->getClientOriginalName(), '.gltf') . "_encoded.gltf";
            Storage::disk('local')->put($encodedFilepath, $encodedGltf);

            // Save the token to the disk
            $tokenFilepath = Str::beforeLast($file->getClientOriginalName(), '.gltf') . '_token.txt';
            Storage::disk('local')->put($tokenFilepath, $generatedToken);

            return response()->json([
                'encoded_path' => Storage::url($encodedFilepath),
                'token_path' => Storage::url($tokenFilepath),
            ]);
        }

        // If no file is uploaded or validation fails, return an error response
        return response()->json(['error' => 'Invalid file'], 400);
    }


    public function upload(Request $request)
    {
        // Check if the request has a file
        if ($request->hasFile('gltf_file')) {
            $file = $request->file('gltf_file');

            // Validate the file (You can add more validation here if needed)
            $validated = $request->validate([
                'gltf_file' => 'file',
            ]);

            // Generate the UID, USHF, and TIMESTAMP_VALIDITY for token generation
            $uid = mt_rand(0, 9999999999);
            $ushf = range(0, 59);
            shuffle($ushf);
            $tsv = 10;

            // Generate the token
            $generatedToken = $this->generateToken($uid, $ushf, $tsv);

            // Read the content of the GLTF file
            $originalGltf = file_get_contents($file->getPathname());
            $decodedGltf = json_decode($originalGltf, true);

            // Encode the GLTF file and generate the encoded GLTF
            $encodedGltf = $this->encodeGLTF($decodedGltf, $generatedToken, $ushf);

            // Save the encoded GLTF to the disk
            $encodedFilepath = Str::beforeLast($file->getClientOriginalName(), '.gltf') . "_encoded.gltf";
            Storage::disk('local')->put($encodedFilepath, $encodedGltf);

            // Save the token to the disk
            $tokenFilepath = Str::beforeLast($file->getClientOriginalName(), '.gltf') . '_token.txt';
            Storage::disk('local')->put($tokenFilepath, $generatedToken);

            return response()->json([
                'encoded_path' => Storage::url($encodedFilepath),
                'token_path' => Storage::url($tokenFilepath),
            ]);
        }

        // If no file is uploaded or validation fails, return an error response
        return response()->json(['error' => 'Invalid file'], 400);
    }
    // Function to generate the token and encode GLTF
    private function generateToken($uid, $ushf, $tsv)
    {
        // Token generation
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
        for ($i = 0; $i < count($idxs); $i++) {
            $token .= $this->num2b60($idxs[$i]);
        }

        // Fill the token payload with blanks. Token payload is 60 ASCII characters long. We do this bcs
        // we need positions in the token string to exist to replace them with symbols later.
        // There are other more elegant ways for filling the payload, but this one is quite simple.
        // Remember that we add yet another blank at the end to make the token 96 characters long, thus "61".
        $token .= str_repeat('.', 61);

        // Write symbols representing UID decimals one by one into formerly randomly chosen positions.
        $uidTemp = str_pad($uid, self::UID_MAX_LENGTH, '0', STR_PAD_LEFT);
        for ($i = 0; $i < self::UID_MAX_LENGTH; $i++) {
            $newToken = substr($token, 0, $headerLength + $idxs[$i]);
            $newToken .= $this->num2b60((int) substr($uidTemp, -1) + (random_int(0, 5) * 10));
            $newToken .= substr($token, $headerLength + $idxs[$i] + 1);
            $token = $newToken;
            $uidTemp = substr($uidTemp, 0, -1);
        }

        // Write symbols representing a unique Shuffling sequence for this particular user.
        // Matrix shuffling positions are in range 0 to 29 for a 6x5 matrix.
        $idxOffset = self::UID_MAX_LENGTH;
        for ($i = 0; $i < self::USHF_MAX_LENGTH; $i++) {
            $newToken = substr($token, 0, $headerLength + $idxs[$i + $idxOffset]);
            $newToken .= $this->num2b60((int) ($ushf[self::USHF_MAX_LENGTH - $i - 1]) + (random_int(0, 1) * 30));
            $newToken .= substr($token, $headerLength + $idxs[$i + $idxOffset] + 1);
            $token = $newToken;
        }

        // Write symbols representing Timestamp decimals.
        $idxOffset += self::USHF_MAX_LENGTH;
        $thisMomentTemp = (string) $thisMoment;
        for ($i = 0; $i < self::TIMESTAMP_MAX_LENGTH; $i++) {
            $newToken = substr($token, 0, $headerLength + $idxs[$i + $idxOffset]);
            $newToken .= $this->num2b60((int) substr($thisMomentTemp, -1) + (random_int(0, 5) * 10));
            $newToken .= substr($token, $headerLength + $idxs[$i + $idxOffset] + 1);
            $token = $newToken;
            $thisMomentTemp = substr($thisMomentTemp, 0, -1);
        }

        // Write symbols representing Timestamp validity decimals.
        $idxOffset += self::UID_MAX_LENGTH;
        $tsvTemp = str_pad($tsv, self::TIMESTAMP_VALIDITY_MAX_LENGTH, '0', STR_PAD_LEFT);
        for ($i = 0; $i < self::TIMESTAMP_VALIDITY_MAX_LENGTH; $i++) {
            $newToken = substr($token, 0, $headerLength + $idxs[$i + $idxOffset]);
            $newToken .= $this->num2b60((int) substr($tsvTemp, -1) + (random_int(0, 5) * 10));
            $newToken .= substr($token, $headerLength + $idxs[$i + $idxOffset] + 1);
            $token = $newToken;
            $tsvTemp = substr($tsvTemp, 0, -1);
        }

        // Fill the remaining blank spaces with random-generated BASE60 symbols.
        for ($i = $headerLength; $i < strlen($token); $i++) {
            if ($token[$i] == '.') {
                $newToken = substr($token, 0, $i);
                $newToken .= $this->num2b60(random_int(0, 59));
                $newToken .= substr($token, $i + 1);
                $token = $newToken;
            }
        }

        return $token;
    }

    private function encodeGLTF($sgltf, $token, $ushf)
    {
        // Construct empty shuffling matrix. It has 6 rows and 5 columns.
        $shMat = [];
        for ($row = 0; $row < 6; $row++) {
            $shMat[] = array_fill(0, 5, 0);
        }

        // Fill unused spaces in the shuffling matrix with random decimals.
        $shOffsetList = [];
        for ($i = 0; $i < self::SHFL_MATRIX_SIZE; $i++) {
            if (!in_array($i, $ushf)) {
                $shMat[$i / 5][$i % 5] = random_int(0, 9);
            }
        }

        // Write hidden key digits into the shuffling matrix.
        $shOffsetList = array_reverse($ushf);
        for ($i = 0; $i < self::USHF_MAX_LENGTH; $i++) {
            $shOffset = $this->b602num($token[self::UID_MAX_LENGTH + $this->b602num($token[$i])]) % 30;
            $shMat[(int) ($shOffset / 5)][$shOffset % 5] = (int) substr($sgltf["accessors"][0]["count"], $i, 1);
        }

        // Convert shuffling matrix into a string representation.
        $shuffledDigits = '';
        foreach ($shMat as $row) {
            foreach ($row as $digit) {
                $shuffledDigits .= (string) $digit;
            }
        }

        // Construct encoded version of the original GLTF file by swapping some least important digits in two float fields.
        $egltf = $sgltf;

        // Delete key values from source GLTF.
        $egltf["accessors"][0]["count"] = 1;
        $egltf["accessors"][1]["count"] = 1;
        $egltf["accessors"][2]["count"] = 1;

        for ($i = 0; $i < 3; $i++) {
            $sVal = (string) $sgltf["accessors"][0]["max"][$i];
            $sEncVal = substr($sVal, 0, -6) . substr($shuffledDigits, $i * 5, 5) . substr($sVal, -1);
            $egltf["accessors"][0]["max"][$i] = (float) $sEncVal;
        }

        for ($i = 0; $i < 3; $i++) {
            $sVal = (string) $sgltf["accessors"][0]["min"][$i];
            $sEncVal = substr($sVal, 0, -6) . substr($shuffledDigits, ($i + 3) * 5, 5) . substr($sVal, -1);
            $egltf["accessors"][0]["min"][$i] = (float) $sEncVal;
        }

        // Convert PHP structure to JSON (GLTF).
        $egltf = json_encode($egltf);

        return $egltf;
    }
    // Calculate numerical value of a BASE60 symbol.

    // Decode GLTF and Token
    function decodeGltfAndToken($egltf, $etkn)
    {

        function b602num($asc)
        {
            if (!preg_match('/^[A-Za-z0-9]+$/', $asc)) {
                // BASE60 decoder received symbol out of range
                return false;
            }

            $num = ord($asc);
            if ($num >= 97) {
                return $num - 97 + 26;
            } else if ($num >= 65) {
                return $num - 65;
            } else {
                return $num - 50 + 52;
            }
        }
        $UID_MAX_LENGTH = 10;
        $USHF_MAX_LENGTH = 10;
        $TIMESTAMP_MAX_LENGTH = 10;
        $TIMESTAMP_VALIDITY_MAX_LENGTH = 5;

        // Copy-pasted offset extracting block from the encoder function. One source of truth.
        $headerLength = $UID_MAX_LENGTH + $USHF_MAX_LENGTH + $TIMESTAMP_MAX_LENGTH + $TIMESTAMP_VALIDITY_MAX_LENGTH;
        $unshOffsetList = [];
        for ($i = 0; $i < $USHF_MAX_LENGTH; $i++) {
            $unshOffset = b602num($etkn[$headerLength + b602num($etkn[$i + $UID_MAX_LENGTH])]) % 30;
            $unshOffsetList[] = $unshOffset;
        }

        // Transcribe data from encoded GLTF into a matrix form.

        // ... (previous code remains unchanged)

        // Transcribe data from encoded GLTF into a matrix form.
        $unshMatrix = array(
            array(0, 0, 0, 0, 0),
            array(0, 0, 0, 0, 0),
            array(0, 0, 0, 0, 0),
            array(0, 0, 0, 0, 0),
            array(0, 0, 0, 0, 0),
            array(0, 0, 0, 0, 0),
        );

        for ($i = 0; $i < 3; $i++) {
            $encVal = mb_substr($egltf["accessors"][0]["max"][$i], -6, 5);
            $chars = str_split($encVal);
            for ($j = 0; $j < 5; $j++) {
                $unshMatrix[$i][$j] = intval($chars[$j]);
            }
        }
        for ($i = 0; $i < 3; $i++) {
            $encVal = mb_substr($egltf["accessors"][0]["min"][$i], -6, 5);
            $chars = str_split($encVal);
            for ($j = 0; $j < 5; $j++) {
                $unshMatrix[$i + 3][$j] = intval($chars[$j]);
            }
        }

        for ($i = 0; $i < 3; $i++) {
            $encVal = substr($egltf["accessors"][0]["min"][$i], -6, 5);
            for ($j = 0; $j < 5; $j++) {
                $unshMatrix[$i + 3][$j] = $encVal[$j];
            }
        }

        // Extract matrix shuffling offsets from token and reconstruct the missing key value.
        $decKey = '';
        foreach ($unshOffsetList as $offset) {
            $decKey .= $unshMatrix[(int) ($offset / 5)][(int) ($offset % 5)];
        }
        $decKey = (string) (int) $decKey;

        // Decode user ID.
        $decUID = '';
        for ($i = 0; $i < $UID_MAX_LENGTH; $i++) {
            $uidDec = (string) (b602num($etkn[$headerLength + b602num($etkn[$i])]) % 10);
            $decUID .= $uidDec;
        }
        $decUID = strrev((string) (int) $decUID); // Reverse string and strip leading zeros

        // Decode UNIX timestamp.
        $decTstp = '';
        for ($i = 0; $i < $TIMESTAMP_MAX_LENGTH; $i++) {
            $tstpDec = (string) (b602num($etkn[$headerLength + b602num($etkn[$i + $UID_MAX_LENGTH + $USHF_MAX_LENGTH])]) % 10);
            $decTstp .= $tstpDec;
        }
        $decTstp = strrev((string) (int) $decTstp);

        // Decode UNIX timestamp validity interval.
        $decTstpVal = '';
        for ($i = 0; $i < $TIMESTAMP_VALIDITY_MAX_LENGTH; $i++) {
            $tstpValDec = (string) (b602num($etkn[$headerLength + b602num($etkn[$i + $UID_MAX_LENGTH + $USHF_MAX_LENGTH + $TIMESTAMP_MAX_LENGTH])]) % 10);
            $decTstpVal .= $tstpValDec;
        }
        $decTstpVal = strrev((string) (int) $decTstpVal);

        // Write decoded hidden key where it belongs.
        $decGltf = $egltf;
        $decGltf["accessors"][0]["count"] = (int) $decKey;
        $decGltf["accessors"][1]["count"] = (int) $decKey;
        $decGltf["accessors"][2]["count"] = (int) $decKey;

        return [
            'decGltf' => json_encode($decGltf),
            'decKey' => $decKey,
            'decUID' => $decUID,
            'decTstp' => $decTstp,
            'decTstpVal' => $decTstpVal,
        ];
    }


}