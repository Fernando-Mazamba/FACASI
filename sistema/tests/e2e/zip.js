// Leitura mínima de ZIP para os testes (usa o utilitário "unzip" do sistema).
import { execFileSync } from 'node:child_process';
export default function zip(path) {
  return {
    read: (name) => execFileSync('unzip', ['-p', path, name], { maxBuffer: 256 * 1024 * 1024 }).toString('utf8'),
    list: () => execFileSync('unzip', ['-Z1', path], { maxBuffer: 64 * 1024 * 1024 }).toString('utf8').split('\n').filter(Boolean),
  };
}
