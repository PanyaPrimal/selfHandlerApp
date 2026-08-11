export interface UiOption<V = string> {
  value: V
  label: string
  disabled?: boolean
}

export type UiTextInputType = 'text' | 'email' | 'password' | 'search'
