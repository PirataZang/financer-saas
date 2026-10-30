// O que muda no texto e nas colunas de uma importação para outra. A rota passa o tipo (`customer` | `user`)
// às telas; campos, regras e modelo vêm da API (/imports/{type}/...).
export const IMPORT_TYPES = {
  customer: {
    path: '/import/customer',
    title: 'Importação de clientes',
    subtitle: 'Envie uma planilha Excel ou CSV: os clientes são criados, ou atualizados se o e-mail ou telefone já existir.',
    subject: 'clientes',
    fieldsOf: 'do cliente',
    matchBy: 'e-mail ou telefone',
    templateFile: 'modelo_importacao_clientes.xlsx',
    help: [
      'Obrigatório: Nome e, ao menos, e-mail ou telefone.',
      'Cliente com o mesmo e-mail ou telefone de um já cadastrado é atualizado com os dados da planilha.',
      'No modelo, Status e Ativo são listas: só aceitam as opções dela.',
    ],
    preview: [['name', 'Nome'], ['email', 'E-mail'], ['phone', 'Telefone'], ['city', 'Cidade']],
  },
  user: {
    path: '/import/user',
    title: 'Importação de usuários',
    subtitle: 'Envie uma planilha Excel ou CSV: os usuários são criados, ou atualizados se o e-mail já existir.',
    subject: 'usuários',
    fieldsOf: 'do usuário',
    matchBy: 'e-mail',
    templateFile: 'modelo_importacao_usuarios.xlsx',
    help: [
      'Obrigatório: Nome, e-mail e, para usuário novo, senha (mínimo 8 caracteres).',
      'Usuário da empresa com o mesmo e-mail é atualizado; sem coluna de senha, a senha dele é mantida.',
      'Os usuários entram sem permissões: libere-as depois em Usuários. No modelo, Ativo é uma lista (Sim/Não).',
    ],
    preview: [['name', 'Nome'], ['email', 'E-mail']],
  },
}
