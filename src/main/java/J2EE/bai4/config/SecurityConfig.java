package J2EE.bai4.config;

import J2EE.bai4.service.AccountService;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import org.springframework.security.config.annotation.web.builders.HttpSecurity;
import org.springframework.security.config.annotation.web.configuration.EnableWebSecurity;
import org.springframework.security.crypto.bcrypt.BCryptPasswordEncoder;
import org.springframework.security.crypto.password.PasswordEncoder;
import org.springframework.security.web.SecurityFilterChain;

@Configuration
@EnableWebSecurity
public class SecurityConfig {

    private final AccountService accountService;

    public SecurityConfig(AccountService accountService) {
        this.accountService = accountService;
    }

    @Bean
    public PasswordEncoder passwordEncoder() {
        return new BCryptPasswordEncoder();
    }

    @Bean
    public SecurityFilterChain securityFilterChain(HttpSecurity http) throws Exception {
        http.userDetailsService(accountService)
                .authorizeHttpRequests(authorize -> authorize
                        .requestMatchers("/products/add", "/products/edit", "/products/edit/**", "/products/delete/**").hasRole("ADMIN")
                        .requestMatchers("/categories/create", "/categories/edit", "/categories/edit/**", "/categories/delete/**").hasRole("ADMIN")
                        .requestMatchers("/order", "/order/**").hasRole("USER")
                        .requestMatchers("/products", "/products/**").hasAnyRole("USER", "ADMIN")
                        .requestMatchers("/categories", "/categories/**").hasAnyRole("ADMIN")
                        .requestMatchers("/images/**", "/css/**", "/js/**", "/error").permitAll()
                        .anyRequest().authenticated())
                .formLogin(form -> form
                        .defaultSuccessUrl("/products", true));
        return http.build();
    }
}
