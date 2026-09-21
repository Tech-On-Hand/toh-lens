// Generates an extension signing key and prints the manifest "key" value and the
// extension ID it produces. Chrome derives the ID from the public key, and the
// native messaging host manifest pins that ID, so generate one key per
// deployment and keep the private key safe (it signs CRX packages).
//
//   node tools/generate-key.mjs --out extension-key.pem
import { createHash, generateKeyPairSync } from 'node:crypto';
import { writeFileSync } from 'node:fs';

const { publicKey, privateKey } = generateKeyPairSync('rsa', { modulusLength: 2048 });
const der = publicKey.export({ type: 'spki', format: 'der' });
const id = [...createHash('sha256').update(der).digest().subarray(0, 16)]
  .map((byte) => byte.toString(16).padStart(2, '0'))
  .join('')
  .replace(/[0-9a-f]/g, (digit) => String.fromCharCode('a'.charCodeAt(0) + parseInt(digit, 16)));

const out = process.argv.indexOf('--out');
if (out !== -1 && process.argv[out + 1]) {
  writeFileSync(process.argv[out + 1], privateKey.export({ type: 'pkcs8', format: 'pem' }), { mode: 0o600 });
  console.log(`private key written to ${process.argv[out + 1]}`);
} else {
  console.log('private key discarded (pass --out <file> to keep it)');
}
console.log(`extension id: ${id}`);
console.log(`manifest key: ${der.toString('base64')}`);
