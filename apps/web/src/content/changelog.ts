/**
 * User-facing changelog.
 *
 * This is product copy for the person who owns this installation, so the text is
 * written in plain Russian and names buttons and screens exactly as they appear
 * in the interface (which is in English). Everything else in the repository —
 * identifiers, comments, documents, tests — stays in English.
 *
 * The list is static on purpose: the content only changes when the application
 * changes, so it ships with the application. There is no endpoint, no table and
 * no editor. See `specs/005-interface-foundation/research.md` R7.
 */

export interface ChangelogLink {
  /** Shown as written; matches the current wording in the interface. */
  readonly label: string
  /** In-application route path, e.g. `/routines`. */
  readonly to: string
}

export interface ChangelogEntry {
  readonly id: string
  /** `YYYY-MM-DD` — the day the change became usable. */
  readonly date: string
  /** Spec Kit feature identifier or category. */
  readonly feature: string
  readonly title: string
  readonly summary: string
  readonly howToTest: string
  readonly links?: readonly ChangelogLink[]
  readonly limitations?: readonly string[]
}

const entries: readonly ChangelogEntry[] = [
  {
    id: 'multi-user-auth',
    date: '2026-08-09',
    feature: '003-multi-user-auth',
    title: 'Вход по приглашению и отдельные аккаунты',
    summary:
      'Появился вход по адресу почты и паролю. Регистрация закрытая: нужен код приглашения. ' +
      'У каждого аккаунта свои рутины, цели, отметки и отчёты — чужие данные не видны и не доступны.',
    howToTest:
      'Выйдите через Sign out на экране Account и войдите заново. Если завести второй аккаунт по ' +
      'другому коду приглашения, его списки будут пустыми и независимыми.',
    links: [{ label: 'Account', to: '/account' }],
  },
  {
    id: 'routines-and-today',
    date: '2026-08-10',
    feature: '001-core-daily-loop',
    title: 'Рутины и экран Today',
    summary:
      'Можно завести повторяющееся действие: название, описание, расписание (каждый день или по ' +
      'дням недели), желаемое время, даты начала и окончания. На экране Today появляется список ' +
      'того, что запланировано на выбранный день, с отметками «сделано» и «пропущено». Рутину ' +
      'можно поставить на паузу, отредактировать или отправить в архив, не теряя историю.',
    howToTest:
      'Откройте Routines, создайте рутину, затем перейдите на Today и отметьте её как выполненную. ' +
      'Повторное нажатие снимает отметку.',
    links: [
      { label: 'Routines', to: '/routines' },
      { label: 'Today', to: '/' },
    ],
    limitations: [
      'После первой отметки расписание рутины блокируется, чтобы прошлые дни не переписывались задним числом.',
    ],
  },
  {
    id: 'daily-review',
    date: '2026-08-10',
    feature: '001-core-daily-loop',
    title: 'Вечерний отчёт за день',
    summary:
      'Появился экран Review: настроение, энергия, стресс и общая оценка дня плюс три текстовых ' +
      'поля — что получилось, что улучшить завтра и свободные заметки. На каждый день сохраняется ' +
      'один отчёт, его можно дополнять в течение дня.',
    howToTest:
      'Откройте Review, выставьте оценки, напишите пару строк и нажмите Save review. Обновите ' +
      'страницу — значения останутся на месте.',
    links: [{ label: 'Review', to: '/review' }],
  },
  {
    id: 'goals',
    date: '2026-08-11',
    feature: '001-core-daily-loop',
    title: 'Цели и связь с рутинами',
    summary:
      'Можно ставить цели с описанием и датой, отмечать их выполненными или отложенными, ' +
      'возвращать в работу и убирать в архив. Цель связывается с рутинами, которые к ней ведут, ' +
      'и связанные цели показываются рядом с рутиной на экране Today.',
    howToTest:
      'Откройте Goals, создайте цель, в блоке Routine links отметьте нужные рутины и нажмите ' +
      'Save routine links. Затем посмотрите на Today — рядом с рутиной появится название цели.',
    links: [
      { label: 'Goals', to: '/goals' },
      { label: 'Today', to: '/' },
    ],
  },
  {
    id: 'progress-and-streaks',
    date: '2026-08-11',
    feature: '001-core-daily-loop',
    title: 'Прогресс за семь дней и серии',
    summary:
      'На экране Today появился блок за последние семь дней: сколько было запланировано, ' +
      'сколько сделано, пропущено и осталось, и какой процент выполнения. У каждой рутины ' +
      'считается текущая серия подряд выполненных дней.',
    howToTest:
      'Отметьте несколько дней подряд на Today, переключая дату в поле Date, и посмотрите, ' +
      'как меняется блок прогресса и счётчик серии у рутины.',
    links: [{ label: 'Today', to: '/' }],
    limitations: [
      'Серия считается по фактическим отметкам. История постановок на паузу пока не восстанавливается.',
    ],
  },
  {
    id: 'profile-and-settings',
    date: '2026-08-12',
    feature: '004-profile-settings',
    title: 'Профиль и настройки',
    summary:
      'Экран Account стал полноценным профилем: имя, часовой пояс, язык и формат даты, система ' +
      'единиц, базовая валюта и тон рекомендаций. Отдельно хранятся исходные данные для расчётов — ' +
      'дата рождения, пол, рост, вес, процент жира, бытовая активность и формула обмена веществ. ' +
      'Именно ваш часовой пояс теперь определяет, какой день считается сегодняшним.',
    howToTest:
      'Откройте Account, поменяйте Units с metric на imperial и нажмите Save profile: рост и вес ' +
      'покажутся в футах и фунтах, но сохранённое значение не изменится.',
    links: [{ label: 'Account', to: '/account' }],
    limitations: [
      'Профиль хранит текущие исходные данные, а не историю измерений.',
    ],
  },
  {
    id: 'interface-foundation',
    date: '2026-08-12',
    feature: '005-interface-foundation',
    title: 'Единый вид полей во всех формах',
    summary:
      'Раньше списки, календарь, выбор времени и галочки рисовались браузером и выбивались из ' +
      'оформления приложения. Теперь все поля свои: выпадающий список, поиск по часовым поясам, ' +
      'календарь, выбор времени, переключатели и галочки выглядят одинаково на всех экранах и ' +
      'полностью работают с клавиатуры. Даты по-прежнему хранятся как календарный день и не ' +
      'сдвигаются из-за часового пояса браузера.',
    howToTest:
      'Откройте Account и нажмите на поле Timezone: появится список с поиском — наберите часть ' +
      'названия города. Затем на Routines откройте Starts on: календарь можно листать стрелками, ' +
      'выбирать день клавишей Enter и закрывать клавишей Escape.',
    links: [
      { label: 'Account', to: '/account' },
      { label: 'Routines', to: '/routines' },
    ],
    limitations: [
      'Ползунки оценок в Review остались стандартными: они и так доступны с клавиатуры и не открывают системных окон.',
    ],
  },
  {
    id: 'changelog',
    date: '2026-08-12',
    feature: '005-interface-foundation',
    title: 'Этот список изменений',
    summary:
      'Появился экран Changelog: что изменилось, когда и как это быстро проверить. Записи идут ' +
      'от новых к старым. На телефоне в нижней панели добавилась кнопка More — через неё ' +
      'открываются Account, Changelog и остальные разделы.',
    howToTest:
      'Вы уже здесь. Сузьте окно до ширины телефона: основные вкладки останутся внизу, ' +
      'а остальное спрячется под кнопку More.',
    links: [{ label: 'Changelog', to: '/changelog' }],
  },
]

/**
 * Newest first, with a total order so same-day entries never swap places between
 * renders. The sort is applied here rather than trusted to authoring order.
 */
export const changelogEntries: readonly ChangelogEntry[] = [...entries].sort((left, right) => {
  if (left.date !== right.date) {
    return left.date < right.date ? 1 : -1
  }

  return left.id < right.id ? 1 : -1
})
